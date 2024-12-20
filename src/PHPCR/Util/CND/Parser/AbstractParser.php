<?php

declare(strict_types=1);

namespace PHPCR\Util\CND\Parser;

use PHPCR\Util\CND\Exception\ParserException;
use PHPCR\Util\CND\Scanner\GenericToken;
use PHPCR\Util\CND\Scanner\GenericToken as Token;
use PHPCR\Util\CND\Scanner\TokenQueue;

/**
 * Abstract base class for parsers.
 *
 * It implements helper functions for parsers:
 *
 *      - checkToken            - check if the next token matches
 *      - expectToken           - expect the next token to match
 *      - checkAndExpectToken   - check and then expect the next token to match
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author Daniel Barsotti <daniel.barsotti@liip.ch>
 */
abstract class AbstractParser
{
    protected TokenQueue $tokenQueue;

    /**
     * Check the next token without consuming it and return true if it matches the given type and data.
     * If the data is not provided (equal to null) then only the token type is checked.
     * Return false otherwise.
     */
    protected function checkToken(int $type, ?string $data = null, bool $ignoreCase = false): bool
    {
        if ($this->tokenQueue->isEof()) {
            return false;
        }

        $token = $this->tokenQueue->peek();

        if ($token->getType() !== $type) {
            return false;
        }

        if ($data && $token->getData() !== $data) {
            if ($ignoreCase && is_string($data) && is_string($token->getData())) {
                return 0 !== strcasecmp($data, $token->getData());
            }

            return false;
        }

        return true;
    }

    /**
     * Check if the token data is one of the elements of the data array.
     *
     * @param string[] $data
     */
    protected function checkTokenIn(int $type, array $data, bool $ignoreCase = false): bool
    {
        foreach ($data as $d) {
            if ($this->checkToken($type, $d, $ignoreCase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the next token matches the expected type and data. If it does, then consume and return it,
     * otherwise throw an exception.
     *
     * @param int         $type The expected token type
     * @param string|null $data The expected token data or null
     *
     * @throws ParserException
     */
    protected function expectToken(int $type, ?string $data = null): Token
    {
        $token = $this->tokenQueue->peek();

        if (!$this->checkToken($type, $data)) {
            throw new ParserException($this->tokenQueue, sprintf("Expected token [%s, '%s']", Token::getTypeName($type), $data));
        }
        \assert($token instanceof GenericToken);
        $this->tokenQueue->next();

        return $token;
    }

    /**
     * Check if the next token matches the expected type and data. If it does, then consume it, otherwise
     * return false.
     *
     * @param int         $type The expected token type
     * @param string|null $data The expected token data or null
     */
    protected function checkAndExpectToken(int $type, ?string $data = null): false|Token
    {
        if ($this->checkToken($type, $data)) {
            $token = $this->tokenQueue->peek();
            $this->tokenQueue->next();

            return $token;
        }

        return false;
    }
}
