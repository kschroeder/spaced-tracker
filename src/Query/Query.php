<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

use SpacedTracker\Catalog\Controller;
use SpacedTracker\Catalog\OutputFormat;
use SpacedTracker\Catalog\RequestClass;

final class Query
{
    /**
     * @param list<Criterion> $criteria
     * @param list<Order> $orders
     * @param list<string> $columns
     * @param list<string> $favorites
     * @param list<string> $queryParameters
     */
    private function __construct(
        private readonly string $className,
        private readonly string $controller,
        private readonly array $criteria,
        private readonly array $orders,
        private readonly ?int $limit,
        private readonly ?int $offset,
        private readonly OutputFormat $format,
        private readonly array $columns,
        private readonly bool $distinct,
        private readonly bool $metadata,
        private readonly array $favorites,
        private readonly bool $showEmpty,
        private readonly array $queryParameters,
    ) {
    }

    public static function create(RequestClass $class): self
    {
        return new self(
            $class->wireName(),
            $class->controller()->value,
            [],
            [],
            null,
            null,
            OutputFormat::Json,
            [],
            false,
            false,
            [],
            true,
            $class->queryParameters(),
        );
    }

    public static function custom(string $className, Controller|string $controller): self
    {
        $controllerName = $controller instanceof Controller ? $controller->value : strtolower($controller);
        Token::assert($className, 'request class');
        Token::assert($controllerName, 'controller');

        return new self(
            strtolower($className),
            $controllerName,
            [],
            [],
            null,
            null,
            OutputFormat::Json,
            [],
            false,
            false,
            [],
            true,
            [],
        );
    }

    public function where(string $field, mixed $value): self
    {
        Token::assert($field, 'predicate field');

        return $this->copy(criteria: [...$this->criteria, new Criterion($field, ValueEncoder::encode($value))]);
    }

    /**
     * @param array<array-key, string> $columns
     */
    public function columns(array $columns): self
    {
        foreach ($columns as $column) {
            Token::assert($column, 'column');
        }

        return $this->copy(columns: array_values($columns));
    }

    public function orderBy(string $field, Direction $direction = Direction::Asc): self
    {
        Token::assert($field, 'order field');

        return $this->copy(orders: [...$this->orders, new Order($field, $direction)]);
    }

    public function limit(int $count, ?int $offset = null): self
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Limit must be at least 1.');
        }
        if ($offset !== null && $offset < 0) {
            throw new \InvalidArgumentException('Offset cannot be negative.');
        }

        return $this->copy(limit: $count, offset: $offset, replaceWindow: true);
    }

    public function format(OutputFormat $format): self
    {
        return $this->copy(format: $format);
    }

    public function distinct(bool $distinct = true): self
    {
        return $this->copy(distinct: $distinct);
    }

    public function withMetadata(bool $metadata = true): self
    {
        return $this->copy(metadata: $metadata);
    }

    public function favorites(string ...$names): self
    {
        foreach ($names as $name) {
            if ($name === '' || str_contains($name, '/') || str_contains($name, '?')) {
                throw new \InvalidArgumentException('Favorite names cannot be empty or contain a slash or question mark.');
            }
        }

        return $this->copy(favorites: array_values($names));
    }

    public function showEmpty(bool $show = true): self
    {
        return $this->copy(showEmpty: $show);
    }

    /**
     * @param array<array-key, string> $fields
     */
    public function withQueryParameters(array $fields): self
    {
        foreach ($fields as $field) {
            Token::assert($field, 'query parameter');
        }

        return $this->copy(queryParameters: array_values($fields));
    }

    public function className(): string
    {
        return $this->className;
    }

    public function controller(): string
    {
        return $this->controller;
    }

    /**
     * @return list<Criterion>
     */
    public function criteria(): array
    {
        return $this->criteria;
    }

    /**
     * @return list<Order>
     */
    public function orders(): array
    {
        return $this->orders;
    }

    public function limitCount(): ?int
    {
        return $this->limit;
    }

    public function offset(): ?int
    {
        return $this->offset;
    }

    public function outputFormat(): OutputFormat
    {
        return $this->format;
    }

    /**
     * @return list<string>
     */
    public function selectedColumns(): array
    {
        return $this->columns;
    }

    public function isDistinct(): bool
    {
        return $this->distinct;
    }

    public function includesMetadata(): bool
    {
        return $this->metadata;
    }

    /**
     * @return list<string>
     */
    public function favoriteNames(): array
    {
        return $this->favorites;
    }

    public function showsEmpty(): bool
    {
        return $this->showEmpty;
    }

    /**
     * @return list<string>
     */
    public function queryParameterFields(): array
    {
        return $this->queryParameters;
    }

    /**
     * @param list<Criterion>|null $criteria
     * @param list<Order>|null $orders
     * @param list<string>|null $columns
     * @param list<string>|null $favorites
     * @param list<string>|null $queryParameters
     */
    private function copy(
        ?array $criteria = null,
        ?array $orders = null,
        ?int $limit = null,
        ?int $offset = null,
        ?OutputFormat $format = null,
        ?array $columns = null,
        ?bool $distinct = null,
        ?bool $metadata = null,
        ?array $favorites = null,
        ?bool $showEmpty = null,
        ?array $queryParameters = null,
        bool $replaceWindow = false,
    ): self {
        return new self(
            $this->className,
            $this->controller,
            $criteria ?? $this->criteria,
            $orders ?? $this->orders,
            $replaceWindow ? $limit : $this->limit,
            $replaceWindow ? $offset : $this->offset,
            $format ?? $this->format,
            $columns ?? $this->columns,
            $distinct ?? $this->distinct,
            $metadata ?? $this->metadata,
            $favorites ?? $this->favorites,
            $showEmpty ?? $this->showEmpty,
            $queryParameters ?? $this->queryParameters,
        );
    }
}
