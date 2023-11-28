<?php

namespace PHPCR\Util\QOM;

use PHPCR\Query\QOM\ColumnInterface;
use PHPCR\Query\QOM\ConstraintInterface;
use PHPCR\Query\QOM\DynamicOperandInterface;
use PHPCR\Query\QOM\JoinConditionInterface;
use PHPCR\Query\QOM\OrderingInterface;
use PHPCR\Query\QOM\QueryObjectModelConstantsInterface;
use PHPCR\Query\QOM\QueryObjectModelFactoryInterface;
use PHPCR\Query\QOM\QueryObjectModelInterface;
use PHPCR\Query\QOM\SourceInterface;
use PHPCR\Query\QueryInterface;
use PHPCR\Query\QueryResultInterface;

/**
 * QueryBuilder class is responsible for dynamically create QOM queries.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author      Nacho Martín <nitram.ohcan@gmail.com>
 * @author      Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author      Benjamin Eberlei <kontakt@beberlei.de>
 */
class QueryBuilder
{
    /** The builder states. */
    public const STATE_DIRTY = 0;
    public const STATE_CLEAN = 1;

    /**
     * @var int The state of the query object. Can be dirty or clean.
     */
    private $state = self::STATE_CLEAN;

    /**
     * @var QueryObjectModelFactoryInterface QOMFactory
     */
    private $qomFactory;

    /**
     * @var int the maximum number of results to retrieve
     */
    private $firstResult;

    /**
     * @var int the maximum number of results to retrieve
     */
    private $maxResults;

    /**
     * @var array with the orderings that determine the order of the result
     */
    private $orderings = [];

    /**
     * @var ConstraintInterface to apply to the query
     */
    private $constraint;

    /**
     * @var array with the columns to be selected
     */
    private $columns = [];

    /**
     * @var SourceInterface source of the query
     */
    private $source;

    /**
     * QOM tree.
     *
     * @var QueryObjectModelInterface
     */
    private $query;

    /**
     * @var array the query parameters
     */
    private $params = [];

    /**
     * Initializes a new QueryBuilder.
     */
    public function __construct(QueryObjectModelFactoryInterface $qomFactory)
    {
        $this->qomFactory = $qomFactory;
    }

    /**
     * Get a query builder instance from an existing query.
     *
     * @param string $statement the statement in the specified language
     * @param string $language  the query language
     *
     * @return QueryBuilder this QueryBuilder instance
     *
     * @throws \InvalidArgumentException
     */
    public function setFromQuery($statement, $language)
    {
        if (QueryInterface::JCR_SQL2 === $language) {
            $converter = new Sql2ToQomQueryConverter($this->qomFactory);
            $statement = $converter->parse($statement);
        }

        if (!$statement instanceof QueryObjectModelInterface) {
            throw new \InvalidArgumentException("Language '$language' not supported");
        }

        $this->state = self::STATE_DIRTY;
        $this->source = $statement->getSource();
        $this->constraint = $statement->getConstraint();
        $this->orderings = $statement->getOrderings();
        $this->columns = $statement->getColumns();

        return $this;
    }

    /**
     * Get the associated QOMFactory for this query builder.
     *
     * @return QueryObjectModelFactoryInterface
     */
    public function getQOMFactory()
    {
        return $this->qomFactory;
    }

    /**
     * Shortcut for getQOMFactory().
     */
    public function qomf()
    {
        return $this->getQOMFactory();
    }

    /**
     * sets the position of the first result to retrieve (the "offset").
     *
     * @param int $firstResult the First result to return
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function setFirstResult($firstResult)
    {
        $this->firstResult = $firstResult;

        return $this;
    }

    /**
     * Gets the position of the first result the query object was set to retrieve (the "offset").
     * Returns NULL if {@link setFirstResult} was not applied to this QueryBuilder.
     *
     * @return int the position of the first result
     */
    public function getFirstResult()
    {
        return $this->firstResult;
    }

    /**
     * Sets the maximum number of results to retrieve (the "limit").
     *
     * @param int $maxResults the maximum number of results to retrieve
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function setMaxResults($maxResults)
    {
        $this->maxResults = $maxResults;

        return $this;
    }

    /**
     * Gets the maximum number of results the query object was set to retrieve (the "limit").
     * Returns NULL if {@link setMaxResults} was not applied to this query builder.
     *
     * @return int maximum number of results
     */
    public function getMaxResults()
    {
        return $this->maxResults;
    }

    /**
     * Gets the array of orderings.
     *
     * @return OrderingInterface[] orderings to apply
     */
    public function getOrderings()
    {
        return $this->orderings;
    }

    /**
     * Adds an ordering to the query results.
     *
     * @param DynamicOperandInterface $sort  the ordering expression
     * @param string                  $order the ordering direction
     *
     * @return QueryBuilder this QueryBuilder instance
     *
     * @throws \InvalidArgumentException
     */
    public function addOrderBy(DynamicOperandInterface $sort, $order = 'ASC')
    {
        $order = strtoupper($order);

        if (!in_array($order, ['ASC', 'DESC'])) {
            throw new \InvalidArgumentException('Order must be one of "ASC" or "DESC"');
        }

        $this->state = self::STATE_DIRTY;
        if ('DESC' === $order) {
            $ordering = $this->qomFactory->descending($sort);
        } else {
            $ordering = $this->qomFactory->ascending($sort);
        }
        $this->orderings[] = $ordering;

        return $this;
    }

    /**
     * Specifies an ordering for the query results.
     * Replaces any previously specified orderings, if any.
     *
     * @param DynamicOperandInterface $sort  the ordering expression
     * @param string                  $order the ordering direction
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function orderBy(DynamicOperandInterface $sort, $order = 'ASC')
    {
        $this->orderings = [];
        $this->addOrderBy($sort, $order);

        return $this;
    }

    /**
     * Specifies one restriction (may be simple or composed).
     * Replaces any previously specified restrictions, if any.
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function where(ConstraintInterface $constraint)
    {
        $this->state = self::STATE_DIRTY;
        $this->constraint = $constraint;

        return $this;
    }

    /**
     * Returns the constraint to apply.
     *
     * @return ConstraintInterface the constraint to be applied
     */
    public function getConstraint()
    {
        return $this->constraint;
    }

    /**
     * Creates a new constraint formed by applying a logical AND to the
     * existing constraint and the new one.
     *
     * Order of ands is important:
     *
     * Given $this->constraint = $constraint1
     * running andWhere($constraint2)
     * resulting constraint will be $constraint1 AND $constraint2
     *
     * If there is no previous constraint then it will simply store the
     * provided one
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function andWhere(ConstraintInterface $constraint)
    {
        $this->state = self::STATE_DIRTY;

        if ($this->constraint) {
            $this->constraint = $this->qomFactory->andConstraint($this->constraint, $constraint);
        } else {
            $this->constraint = $constraint;
        }

        return $this;
    }

    /**
     * Creates a new constraint formed by applying a logical OR to the
     * existing constraint and the new one.
     *
     * Order of ands is important:
     *
     * Given $this->constraint = $constraint1
     * running orWhere($constraint2)
     * resulting constraint will be $constraint1 OR $constraint2
     *
     * If there is no previous constraint then it will simply store the
     * provided one
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function orWhere(ConstraintInterface $constraint)
    {
        $this->state = self::STATE_DIRTY;

        if ($this->constraint) {
            $this->constraint = $this->qomFactory->orConstraint($this->constraint, $constraint);
        } else {
            $this->constraint = $constraint;
        }

        return $this;
    }

    /**
     * Returns the columns to be selected.
     *
     * @return ColumnInterface[] The columns to be selected
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * Sets the columns to be selected.
     *
     * @param ColumnInterface[] $columns The columns to be selected
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function setColumns(array $columns)
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * Identifies a property in the specified or default selector to include in the tabular view of query results.
     * Replaces any previously specified columns to be selected if any.
     *
     * @param string $selectorName
     * @param string $propertyName
     * @param string $columnName
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function select($selectorName, $propertyName, $columnName = null)
    {
        $this->state = self::STATE_DIRTY;
        $this->columns = [$this->qomFactory->column($selectorName, $propertyName, $columnName)];

        return $this;
    }

    /**
     * Adds a property in the specified or default selector to include in the tabular view of query results.
     *
     * @param string $selectorName
     * @param string $propertyName
     * @param string $columnName
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function addSelect($selectorName, $propertyName, $columnName = null)
    {
        $this->state = self::STATE_DIRTY;

        $this->columns[] = $this->qomFactory->column($selectorName, $propertyName, $columnName);

        return $this;
    }

    /**
     * Sets the default Selector or the node-tuple Source. Can be a selector
     * or a join.
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function from(SourceInterface $source)
    {
        $this->state = self::STATE_DIRTY;
        $this->source = $source;

        return $this;
    }

    /**
     * Gets the default Selector.
     *
     * @return SourceInterface the default selector
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * Performs an inner join between the stored source and the supplied source.
     *
     * @return QueryBuilder this QueryBuilder instance
     *
     * @throws \RuntimeException if there is not an existing source
     */
    public function join(SourceInterface $rightSource, JoinConditionInterface $joinCondition)
    {
        return $this->innerJoin($rightSource, $joinCondition);
    }

    /**
     * Performs an inner join between the stored source and the supplied source.
     *
     * @return QueryBuilder this QueryBuilder instance
     *
     * @throws \RuntimeException if there is not an existing source
     */
    public function innerJoin(SourceInterface $rightSource, JoinConditionInterface $joinCondition)
    {
        return $this->joinWithType($rightSource, QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_INNER, $joinCondition);
    }

    /**
     * Performs an left outer join between the stored source and the supplied source.
     *
     * @return QueryBuilder this QueryBuilder instance
     *
     * @throws \RuntimeException if there is not an existing source
     */
    public function leftJoin(SourceInterface $rightSource, JoinConditionInterface $joinCondition)
    {
        return $this->joinWithType($rightSource, QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_LEFT_OUTER, $joinCondition);
    }

    /**
     * Performs a right outer join between the stored source and the supplied source.
     *
     * @return QueryBuilder this QueryBuilder instance
     *
     * @throws \RuntimeException if there is not an existing source
     */
    public function rightJoin(SourceInterface $rightSource, JoinConditionInterface $joinCondition)
    {
        return $this->joinWithType($rightSource, QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_RIGHT_OUTER, $joinCondition);
    }

    /**
     * Performs an join between the stored source and the supplied source.
     *
     * @param string $joinType as specified in PHPCR\Query\QOM\QueryObjectModelConstantsInterface
     *
     * @return QueryBuilder this QueryBuilder instance
     *
     * @throws \RuntimeException if there is not an existing source
     */
    public function joinWithType(SourceInterface $rightSource, $joinType, JoinConditionInterface $joinCondition)
    {
        if (!$this->source) {
            throw new \RuntimeException('Cannot perform a join without a previous call to from');
        }

        $this->state = self::STATE_DIRTY;
        $this->source = $this->qomFactory->join($this->source, $rightSource, $joinType, $joinCondition);

        return $this;
    }

    /**
     * Gets the query built.
     *
     * @return QueryObjectModelInterface
     */
    public function getQuery()
    {
        if (null !== $this->query && self::STATE_CLEAN === $this->state) {
            return $this->query;
        }

        $this->state = self::STATE_CLEAN;
        $this->query = $this->qomFactory->createQuery($this->source, $this->constraint, $this->orderings, $this->columns);

        if ($this->firstResult) {
            $this->query->setOffset($this->firstResult);
        }

        if ($this->maxResults) {
            $this->query->setLimit($this->maxResults);
        }

        return $this->query;
    }

    /**
     * Executes the query setting firstResult and maxResults.
     *
     * @return QueryResultInterface
     */
    public function execute()
    {
        if (null === $this->query || self::STATE_DIRTY === $this->state) {
            $this->query = $this->getQuery();
        }

        foreach ($this->params as $key => $value) {
            $this->query->bindValue($key, $value);
        }

        return $this->query->execute();
    }

    /**
     * Sets a query parameter for the query being constructed.
     *
     * @param string $key   the parameter name
     * @param mixed  $value the parameter value
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function setParameter($key, $value)
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Gets a (previously set) query parameter of the query being constructed.
     *
     * @param string $key the key (name) of the bound parameter
     *
     * @return mixed the value of the bound parameter
     */
    public function getParameter($key)
    {
        return isset($this->params[$key]) ? $this->params[$key] : null;
    }

    /**
     * Sets a collection of query parameters for the query being constructed.
     *
     * @param array $params the query parameters to set
     *
     * @return QueryBuilder this QueryBuilder instance
     */
    public function setParameters(array $params)
    {
        $this->params = $params;

        return $this;
    }

    /**
     * Gets all defined query parameters for the query being constructed.
     *
     * @return array the currently defined query parameters
     */
    public function getParameters()
    {
        return $this->params;
    }
}
