<?php

declare(strict_types=1);

namespace PHPCR\Util;

use PHPCR\ItemExistsException;
use PHPCR\ItemInterface;
use PHPCR\ItemNotFoundException;
use PHPCR\Lock\LockException;
use PHPCR\NamespaceException;
use PHPCR\NodeInterface;
use PHPCR\NodeType\ConstraintViolationException;
use PHPCR\PathNotFoundException;
use PHPCR\RepositoryException;
use PHPCR\SessionInterface;
use PHPCR\Version\VersionException;

/**
 * Helper with only static methods to work with PHPCR nodes.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author Daniel Barsotti <daniel.barsotti@liip.ch>
 * @author David Buchmann <mail@davidbu.ch>
 */
class NodeHelper
{
    /**
     * Do not create an instance of this class.
     */
    private function __construct()
    {
    }

    /**
     * Create a node and it's parents, if necessary.
     *
     * Like mkdir -p.
     *
     * @param SessionInterface $session the PHPCR session to create the path
     * @param string           $path    full path, like /content/jobs/data
     *
     * @return NodeInterface the last node of the path, i.e. data
     *
     * @throws \InvalidArgumentException
     * @throws RepositoryException
     * @throws PathNotFoundException
     * @throws ItemExistsException
     * @throws LockException
     * @throws ConstraintViolationException
     * @throws VersionException
     */
    public static function createPath(SessionInterface $session, string $path): NodeInterface
    {
        $current = $session->getRootNode();

        $segments = preg_split('#/#', $path, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($segments as $segment) {
            if ($current->hasNode($segment)) {
                $current = $current->getNode($segment);
            } else {
                $current = $current->addNode($segment);
            }
        }

        return $current;
    }

    /**
     * Delete all content in the workspace this session is bound to.
     *
     * Remember to save the session after calling the purge method.
     *
     * Note that if you want to delete a node under your root node, you can just
     * use the remove method on that node. This method is just here to help you
     * because the implementation might add nodes like jcr:system to the root
     * node which you are not allowed to remove.
     *
     * @param SessionInterface $session the session to remove all children of
     *                                  the root node
     *
     * @throws RepositoryException
     *
     * @see isSystemItem
     */
    public static function purgeWorkspace(SessionInterface $session): void
    {
        $root = $session->getRootNode();

        foreach ($root->getProperties() as $property) {
            if (!self::isSystemItem($property)) {
                $property->remove();
            }
        }

        foreach ($root->getNodes() as $node) {
            if (!self::isSystemItem($node)) {
                $node->remove();
            }
        }
    }

    /**
     * Determine whether this item is to be considered a system item that you
     * usually want to hide and that should not be removed when purging the
     * repository.
     *
     * @throws RepositoryException
     */
    public static function isSystemItem(ItemInterface $item): bool
    {
        if ($item->getDepth() > 1) {
            return false;
        }
        $name = $item->getName();

        return str_starts_with($name, 'jcr:') || str_starts_with($name, 'rep:');
    }

    /**
     * Helper method to implement NodeInterface::addNodeAutoNamed.
     *
     * This method only checks for valid namespaces. All other exceptions must
     * be thrown by the addNodeAutoNamed implementation.
     *
     * @param string[]    $usedNames        list of child names that is currently used and may not be chosen
     * @param string[]    $namespaces       namespace prefix to uri map of all currently known namespaces
     * @param string      $defaultNamespace namespace prefix to use if the hint does not specify
     * @param string|null $nameHint         the name hint according to the API definition
     *
     * @return string A valid node name for this node
     * @return string A valid node name for this node
     *
     * @throws RepositoryException
     * @throws NamespaceException  if a namespace prefix is provided in the
     *                             $nameHint which does not exist and this implementation performs
     *                             this validation immediately
     */
    public static function generateAutoNodeName(array $usedNames, array $namespaces, string $defaultNamespace, ?string $nameHint = null): string
    {
        $usedNames = array_flip($usedNames);

        /*
         * null: The new node name will be generated entirely by the repository.
         */
        if (null === $nameHint) {
            return self::generateWithPrefix($usedNames, $defaultNamespace.':');
        }

        /*
         * "" (the empty string), ":" (colon) or "{}": The new node name will
         * be in the empty namespace and the local part of the name will be
         * generated by the repository.
         */
        if ('' === $nameHint || ':' === $nameHint || '{}' === $nameHint) {
            return self::generateWithPrefix($usedNames, '');
        }

        /*
         * "<i>somePrefix</i>:" where <i>somePrefix</i> is a syntactically
         * valid namespace prefix
         */
        if (':' === $nameHint[strlen($nameHint) - 1]
            && 1 === substr_count($nameHint, ':')
            && preg_match('#^[a-zA-Z][a-zA-Z0-9]*:$#', $nameHint)
        ) {
            $prefix = substr($nameHint, 0, -1);
            if (!isset($namespaces[$prefix])) {
                throw new NamespaceException("Invalid nameHint '$nameHint'");
            }

            return self::generateWithPrefix($usedNames, $prefix.':');
        }

        /*
         * "{<i>someURI</i>}" where <i>someURI</i> is a syntactically valid
         * namespace URI
         */
        if (strlen($nameHint) > 2
            && '{' === $nameHint[0]
            && '}' === $nameHint[strlen($nameHint) - 1]
            && filter_var(substr($nameHint, 1, -1), FILTER_VALIDATE_URL)
        ) {
            $prefix = array_search(substr($nameHint, 1, -1), $namespaces);
            if (!$prefix) {
                throw new NamespaceException("Invalid nameHint '$nameHint'");
            }

            return self::generateWithPrefix($usedNames, $prefix.':');
        }

        /*
         * "<i>somePrefix</i>:<i>localNameHint</i>" where <i>somePrefix</i> is
         * a syntactically valid namespace prefix and <i>localNameHint</i> is
         * syntactically valid local name: The repository will attempt to create a
         * name in the namespace represented by that prefix as described in (3),
         * above. The local part of the name is generated by the repository using
         * <i>localNameHint</i> as a basis. The way in which the local name is
         * constructed from the hint may vary across implementations.
         */
        if (1 === substr_count($nameHint, ':')) {
            [$prefix, $name] = explode(':', $nameHint);
            if (preg_match('#^[a-zA-Z][a-zA-Z0-9]*$#', $prefix)
                && preg_match('#^[a-zA-Z][a-zA-Z0-9]*$#', $name)
            ) {
                if (!isset($namespaces[$prefix])) {
                    throw new NamespaceException("Invalid nameHint '$nameHint'");
                }

                return self::generateWithPrefix($usedNames, $prefix.':', $name);
            }
        }

        /*
         * "{<i>someURI</i>}<i>localNameHint</i>" where <i>someURI</i> is a
         * syntactically valid namespace URI and <i>localNameHint</i> is
         * syntactically valid local name: The repository will attempt to create a
         * name in the namespace specified as described in (4), above. The local
         * part of the name is generated by the repository using <i>localNameHint</i>
         * as a basis. The way in which the local name is constructed from the hint
         * may vary across implementations.
         */
        $matches = [];
        if (preg_match('#^\\{([^\\}]+)\\}([a-zA-Z][a-zA-Z0-9]*)$#', $nameHint, $matches)) {
            $ns = $matches[1];
            $name = $matches[2];

            $prefix = array_search($ns, $namespaces, true);
            if (!$prefix) {
                throw new NamespaceException("Invalid nameHint '$nameHint'");
            }

            return self::generateWithPrefix($usedNames, $prefix.':', $name);
        }

        throw new RepositoryException("Invalid nameHint '$nameHint'");
    }

    /**
     * Repeatedly generate a name with a random part until we hit one that is
     * not yet used.
     *
     * @param string[] $usedNames names that are forbidden
     * @param string   $prefix    the prefix including the colon at the end
     * @param string   $namepart  start for the localname
     */
    private static function generateWithPrefix(array $usedNames, string $prefix, string $namepart = ''): string
    {
        do {
            $name = $prefix.$namepart.mt_rand();
        } while (isset($usedNames[$name]));

        return $name;
    }

    /**
     * Compare two arrays and generate a list of move operations that executed
     * in order will transform $old into $new.
     *
     * The result is an array with the keys being elements of the array to move
     * right before the element in the value. A value of null means move to the
     * end.
     *
     * If $old contains elements not present in $new, those elements are
     * ignored and do not show up.
     *
     * @param array $old old order
     * @param array $new new order
     *
     * @return array the keys are elements to move, values the destination to
     *               move before or null to move to the end
     */
    public static function calculateOrderBefore(array $old, array $new): array
    {
        $reorders = [];

        // check for deleted items
        $newIndex = array_flip($new);

        foreach ($old as $key => $value) {
            if (!array_key_exists($value, $newIndex)) {
                unset($old[$key]);
            }
        }

        // reindex the arrays to avoid holes in the indexes
        $old = array_values($old);
        $new = array_values($new);

        $len = count($new) - 1;
        $oldIndex = array_flip($old);

        // go backwards on the new node order and arrange them this way
        for ($i = $len; $i >= 0; --$i) {
            // get the name of the child node
            $current = $new[$i];
            // check if it's not the last node
            if (isset($new[$i + 1])) {
                // get the name of the next node
                $next = $new[$i + 1];
                // if in the old order $c and next are not neighbors already, do the reorder command
                if ($oldIndex[$current] + 1 !== $oldIndex[$next]) {
                    $reorders[$current] = $next;
                    $old = self::orderBeforeArray($current, $next, $old);
                    $oldIndex = array_flip($old);
                }
            } else {
                // check if it's not already at the end of the nodes
                if ($oldIndex[$current] !== $len) {
                    $reorders[$current] = null;
                    $old = self::orderBeforeArray($current, null, $old);
                    $oldIndex = array_flip($old);
                }
            }
        }

        return $reorders;
    }

    /**
     * Move the element $name of $list to right before $destination,
     * validating existence of all elements.
     *
     * @param string      $name        name of the element to move
     * @param string|null $destination name of the element $srcChildRelPath has
     *                                 to be ordered before, null to move to the end
     * @param array       $list        the array of names
     *
     * @return array The updated $nodes array with new order
     *
     * @throws ItemNotFoundException if $srcChildRelPath or $destChildRelPath are not found in $nodes
     */
    public static function orderBeforeArray(string $name, ?string $destination, array $list): array
    {
        // reindex the array so there are no gaps
        $list = array_values($list);
        $oldpos = array_search($name, $list, true);

        if (false === $oldpos) {
            throw new ItemNotFoundException("$name is not a child of this node");
        }

        if (null === $destination) {
            // null means move to end
            unset($list[$oldpos]);
            $list[] = $name;
        } else {
            // insert before element $destination
            $newpos = array_search($destination, $list, true);
            if (false === $newpos) {
                throw new ItemNotFoundException("$destination is not a child of this node");
            }
            if ($oldpos < $newpos) {
                // we first unset, the position will change by one
                --$newpos;
            }
            unset($list[$oldpos]);
            array_splice($list, $newpos, 0, $name);
        }

        return $list;
    }
}
