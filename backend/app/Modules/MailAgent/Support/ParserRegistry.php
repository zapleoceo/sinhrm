<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Support;

use App\Modules\MailAgent\Contracts\MailParser;
use App\Modules\MailAgent\Enums\ParserKey;
use LogicException;

/** Parsers by key (tag MailAgentServiceProvider::PARSERS_TAG); an unknown/null key falls back to "generic". */
final class ParserRegistry
{
    /** @var array<string, MailParser> */
    private array $parsers = [];

    /** @param  iterable<MailParser>  $parsers */
    public function __construct(iterable $parsers)
    {
        foreach ($parsers as $parser) {
            $key = $parser->key()->value;
            if (isset($this->parsers[$key])) {
                throw new LogicException('Duplicate mail parser: '.$key);
            }
            $this->parsers[$key] = $parser;
        }
    }

    public function get(?ParserKey $key): MailParser
    {
        return $this->parsers[($key ?? ParserKey::Generic)->value]
            ?? $this->parsers[ParserKey::Generic->value]
            ?? throw new LogicException('The generic mail parser is not registered');
    }
}
