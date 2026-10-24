<?php

declare(strict_types=1);

namespace App\Services\PepDirectory\DTOs;

use App\Exceptions\PepDirectory\PreviewCompilationException;
use App\Services\PepDirectory\ProcessPreviewCompiler;
use stdClass;

/** Integrity-checked data, NOT evidence of origin, qualification or approval. */
final readonly class CompiledPreviewData
{
    private function __construct(
        public string $canonicalBytes,
        public string $revision,
        public string $resultBytes,
        public string $resultSha256,
        public string $validity,
        public array $wire,
    ) {}

    /** INTERNAL controlled-process output only. Never accept browser artifacts here. */
    public static function fromOutput(string $output, array $declaration, string $rawBody, array $metadata): self
    {
        try {
            self::check(strlen($output) <= ProcessPreviewCompiler::MAX_OUTPUT && strlen($rawBody) <= ProcessPreviewCompiler::MAX_BODY);
            $a = self::json($output);
            self::keys($a, ['format_version', 'configuration', 'capture', 'preview']);
            self::check($a->format_version === 1);
            $c = $a->configuration;
            $capture = $a->capture;
            $p = $a->preview;
            self::keys($c, ['declaration', 'canonical_base64', 'revision']);
            self::keys($capture, ['body_base64', 'content_sha256', 'metadata']);
            self::keys($p, ['wire', 'result_base64', 'result_sha256', 'validity']);
            $requestDeclaration = self::json(json_encode($declaration, JSON_THROW_ON_ERROR));
            self::check(self::same($c->declaration, $requestDeclaration));
            self::check(self::base64($capture->body_base64) === $rawBody && $capture->content_sha256 === hash('sha256', $rawBody));
            self::keys($capture->metadata, ['status', 'source_url', 'observed_at', 'encoding']);
            self::check(array_keys($metadata) !== [] && count($metadata) === 4);
            foreach (['status', 'source_url', 'encoding'] as $key) {
                self::check(array_key_exists($key, $metadata) && $capture->metadata->$key === $metadata[$key]);
            }
            self::check(is_int($metadata['status']) && $metadata['status'] >= 100 && $metadata['status'] <= 599);
            self::check(is_string($metadata['encoding']) && preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $metadata['encoding']) === 1);
            self::check($capture->metadata->source_url === $requestDeclaration->url);
            self::check(self::instant($capture->metadata->observed_at) === self::instant($metadata['observed_at'] ?? null));

            $canonical = self::base64($c->canonical_base64);
            $identity = self::producerJson($canonical);
            self::sortedObjects($identity);
            self::keys($identity, ['contract', 'config']);
            self::check($identity->contract === 'configured-html-v1');
            self::check(self::same($identity->config, self::normalizedDeclaration($requestDeclaration)));
            // Consume Python bytes, never create a competing PHP extraction/canonical compiler.
            self::check(is_string($c->revision) && $c->revision === 'configured-html-v1:'.hash('sha256', $canonical));
            $result = self::base64($p->result_base64);
            self::check($p->result_sha256 === hash('sha256', $result));
            $wire = self::producerJson($result);
            self::check(self::same($wire, $p->wire));
            self::orderedKeys($wire, ['source', 'revision', 'observed_at', 'validity', 'positions', 'evidence']);
            self::check($wire->source === $requestDeclaration->source && $wire->revision === $c->revision);
            self::check($wire->observed_at === $capture->metadata->observed_at);
            self::check(is_string($p->validity) && in_array($p->validity, ['failed', 'blocked', 'empty', 'incomplete', 'ambiguous'], true) && $wire->validity === $p->validity);
            self::check(is_array($wire->positions) && is_string($wire->evidence));
            self::check($wire->validity === 'incomplete' || $wire->positions === []);
            foreach ($wire->positions as $position) {
                self::orderedKeys($position, ['unit', 'role', 'person', 'condition', 'evidence']);
                foreach (['unit', 'role', 'person', 'condition', 'evidence'] as $key) {
                    $value = $position->$key;
                    self::check(is_string($value) || (in_array($key, ['person', 'condition'], true) && $value === null));
                    self::check($key === 'evidence' || $value === null || trim($value) !== '');
                }
            }
            return new self($canonical, $c->revision, $result, $p->result_sha256, $p->validity, json_decode($result, true, 32, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new PreviewCompilationException();
        }
    }

    /** Compare the Python dataclass identity shape, not a PHP canonical byte generator. */
    private static function normalizedDeclaration(stdClass $d): stdClass
    {
        $keys = array_keys(get_object_vars($d));
        self::check(array_diff(['source', 'url', 'directory', 'record', 'fields'], $keys) === [] && array_diff($keys, ['source', 'url', 'directory', 'record', 'fields', 'section', 'columns']) === []);
        foreach (['source', 'url', 'directory', 'record'] as $key) {
            self::check(is_string($d->$key) && trim($d->$key) !== '');
        }
        self::check(! property_exists($d, 'section') || (is_string($d->section) && trim($d->section) !== ''));
        self::check(! property_exists($d, 'columns') || (is_int($d->columns) && $d->columns >= 1 && $d->columns <= 32));
        self::check($d->fields instanceof stdClass);
        $fields = get_object_vars($d->fields);
        self::check(array_diff(['unit', 'role', 'person'], array_keys($fields)) === [] && array_diff(array_keys($fields), ['unit', 'role', 'person', 'condition']) === []);
        ksort($fields);
        $normalized = [];
        foreach ($fields as $name => $field) {
            self::check($field instanceof stdClass);
            $values = get_object_vars($field);
            if (property_exists($field, 'constant')) {
                self::keys($field, ['constant']);
                self::check($name === 'unit' && is_string($field->constant) && trim($field->constant) !== '');
            } elseif (property_exists($field, 'column')) {
                self::keys($field, ['column']);
                self::check(is_int($field->column) && $field->column >= 0 && isset($d->columns) && $field->column < $d->columns);
            } else {
                self::keys($field, property_exists($field, 'scope') ? ['selector', 'scope'] : ['selector']);
                self::check(is_string($field->selector) && trim($field->selector) !== '');
                $scope = $field->scope ?? 'record';
                self::check(in_array($scope, ['record', 'section', 'directory'], true) && ($name === 'unit' || $scope === 'record') && ($scope !== 'section' || isset($d->section)));
                self::check(! property_exists($field, 'scope') || is_string($field->scope));
            }
            $normalized[] = [$name, (object) array_replace(['scope' => 'record', 'selector' => null, 'column' => null, 'constant' => null], $values)];
        }
        return (object) ['source' => $d->source, 'url' => $d->url, 'directory' => $d->directory, 'record' => $d->record, 'fields' => $normalized, 'section' => $d->section ?? null, 'columns' => $d->columns ?? null];
    }

    private static function instant(\stdClass|string|int|bool|array|null $value): string
    {
        // Python ISO aware timestamps, including Z, optional fractional seconds and offsets.
        self::check(is_string($value) && strlen($value) <= 64 && preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) === 1);
        $date = new \DateTimeImmutable($value);
        self::check($date::getLastErrors() === false);
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u');
    }

    private static function base64(\stdClass|string|int|bool|array|null $value): string
    {
        self::check(is_string($value));
        $bytes = base64_decode($value, true);
        self::check($bytes !== false && base64_encode($bytes) === $value);
        return $bytes;
    }

    /** Validate Python ensure_ascii compact lexical spelling, without generating identities. */
    private static function producerJson(string $bytes): stdClass
    {
        $value = self::json($bytes);
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\]:,]|-?[0-9]+|true|false|null/s', $bytes, $matches);
        self::check(implode('', $matches[0]) === $bytes);
        foreach ($matches[0] as $token) {
            if (str_starts_with($token, '"')) {
                $text = json_decode($token, true, 32, JSON_THROW_ON_ERROR);
                $spelling = json_encode($text, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                // Python ensure_ascii escapes DEL too; PHP's ASCII encoder does not.
                self::check(str_replace("\x7f", '\\u007f', $spelling) === $token);
            } else {
                self::check($token !== '-0');
            }
        }
        return $value;
    }

    /** Python canonical identity sorts every object; arrays retain their supplied order. */
    private static function sortedObjects(stdClass|array $node): void
    {
        if ($node instanceof stdClass) {
            $keys = array_keys(get_object_vars($node));
            $sorted = $keys;
            sort($sorted, SORT_STRING);
            self::check($keys === $sorted);
        }
        foreach ($node as $value) {
            if ($value instanceof stdClass || is_array($value)) {
                self::sortedObjects($value);
            }
        }
    }

    private static function orderedKeys(stdClass $value, array $keys): void
    {
        self::check(array_keys(get_object_vars($value)) === $keys);
    }

    /** Strict JSON subset of this compiler: objects/lists, strings, ints, bool, null. */
    private static function json(string $bytes): stdClass
    {
        $value = json_decode($bytes, false, 32, JSON_THROW_ON_ERROR);
        self::check($value instanceof stdClass);
        // json_decode validates grammar/trailing data; this scan detects even escaped duplicate keys.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\],]/s', $bytes, $tokens);
        $stack = [];
        foreach ($tokens[0] as $token) {
            $last = count($stack) - 1;
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'key' => true, 'seen' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif ($last >= 0 && $token === ',') {
                $stack[$last]['key'] = true;
            } elseif ($last >= 0 && $stack[$last]['object'] && $stack[$last]['key']) {
                $key = json_decode($token, true, 32, JSON_THROW_ON_ERROR);
                self::check(! array_key_exists($key, $stack[$last]['seen']));
                $stack[$last]['seen'][$key] = true;
                $stack[$last]['key'] = false;
            }
        }
        self::tree($value);
        return $value;
    }

    /** mixed is necessary for a recursively decoded, not-yet-validated JSON node. */
    private static function tree(mixed $value): void
    {
        if ($value instanceof stdClass || is_array($value)) {
            foreach ($value as $key => $item) {
                self::check(! is_string($key) || ! str_contains($key, "\0"));
                self::tree($item);
            }
        } else {
            self::check($value === null || is_int($value) || is_bool($value) || (is_string($value) && ! str_contains($value, "\0")));
        }
    }

    /** mixed permits type checks at the untrusted decoded boundary. */
    private static function keys(mixed $value, array $keys): void
    {
        self::check($value instanceof stdClass && count(get_object_vars($value)) === count($keys) && array_diff($keys, array_keys(get_object_vars($value))) === []);
    }

    /** Strict types and object-vs-list distinction; JSON object member order is immaterial. */
    private static function same(mixed $left, mixed $right): bool
    {
        if ($left instanceof stdClass && $right instanceof stdClass) {
            $left = get_object_vars($left);
            $right = get_object_vars($right);
            ksort($left);
            ksort($right);
        } elseif (get_debug_type($left) !== get_debug_type($right)) {
            return false;
        }
        if (! is_array($left)) {
            return $left === $right;
        }
        if (array_keys($left) !== array_keys($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (! self::same($value, $right[$key])) {
                return false;
            }
        }
        return true;
    }

    private static function check(bool $condition): void
    {
        if (! $condition) {
            throw new PreviewCompilationException();
        }
    }
}
