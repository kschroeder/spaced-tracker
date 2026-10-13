<?php

declare(strict_types=1);

namespace SpacedTracker\Query;

final class PathCompiler
{
    public function compile(Query $query): string
    {
        $segments = [
            $query->controller(),
            'query',
            'class',
            $query->className(),
        ];
        $queryParams = [];
        $paramFields = array_map(static fn (string $field): string => strtolower($field), $query->queryParameterFields());

        foreach ($query->criteria() as $criterion) {
            if (in_array(strtolower($criterion->field), $paramFields, true)) {
                $queryParams[$criterion->field] = $criterion->value;
                continue;
            }
            $segments[] = $criterion->field;
            $segments[] = $this->encode($criterion->value);
        }

        if ($query->orders() !== []) {
            $orders = [];
            foreach ($query->orders() as $order) {
                $orders[] = $order->field . ' ' . $order->direction->value;
            }
            $segments[] = 'orderby';
            $segments[] = $this->encode(implode(',', $orders));
        }

        if ($query->limitCount() !== null) {
            $limit = (string) $query->limitCount();
            if ($query->offset() !== null) {
                $limit .= ',' . $query->offset();
            }
            $segments[] = 'limit';
            $segments[] = $limit;
        }

        if ($query->selectedColumns() !== []) {
            $segments[] = 'predicates';
            $segments[] = implode(',', $query->selectedColumns());
        }

        if ($query->isDistinct()) {
            $segments[] = 'distinct';
            $segments[] = 'true';
        }

        if ($query->includesMetadata()) {
            $segments[] = 'metadata';
            $segments[] = 'true';
        }

        if ($query->favoriteNames() !== []) {
            $segments[] = 'favorites';
            $segments[] = $this->encode(implode(',', $query->favoriteNames()));
        }

        if ($query->showsEmpty()) {
            $segments[] = 'emptyresult';
            $segments[] = 'show';
        }

        $segments[] = 'format';
        $segments[] = $query->outputFormat()->value;

        $path = '/' . implode('/', $segments);
        if ($queryParams !== []) {
            $path .= '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        }

        return $path;
    }

    public function modelDefinitionPath(string $controller, string $class): string
    {
        Token::assert($controller, 'controller');
        Token::assert($class, 'request class');

        return sprintf('/%s/modeldef/class/%s/format/json', strtolower($controller), strtolower($class));
    }

    private function encode(string $value): string
    {
        return str_replace(['%2C', '%7E'], [',', '~'], rawurlencode($value));
    }
}
