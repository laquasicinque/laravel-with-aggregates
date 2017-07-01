<?php


namespace LaQuasiCinque\WithAggregate;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class WithAggregateServiceProvider extends ServiceProvider
{
    private $aggregates = ['SUM', 'AVG', 'MIN', 'MAX'];

    public function register()
    {
        Builder::macro('withSum');
        $this->addGetRelationExistenceAggregateQueryToRelation();
        $this->addWithAggregateToBuilder();
        $this->addWithAggregates();
    }

    private function addGetRelationExistenceAggregateQueryToRelation()
    {
        Relation::macro('getRelationExistenceAggregateQuery', function (Builder $query, Builder $parentQuery, $method, $column) {
            return $this->getRelationExistenceQuery(
                $query, $parentQuery, new Expression($method . '(' . $this->query->getQuery()->getGrammar()->wrap($column) . ')')
            );
        });
    }

    private function addWithAggregates()
    {
        foreach ($this->aggregates as $aggregate) {
            Builder::macro('with' . ucfirst($aggregate), function ($relations) use ($aggregate) {
                $relations = is_array($relations) ? $relations : func_get_args();
                return $this->withAggregate($relations, $aggregate);
            });
//            unset($aggregate);
        }
    }

    private function addWithAggregateToBuilder()
    {
        Builder::macro('withAggregate', function ($relations, $method = 'SUM') {
            if (empty($relations)) {
                return $this;
            }

            if (is_null($this->query->columns)) {
                $this->query->select([$this->query->from . '.*']);
            }

            $method = Str::lower($method);

            // Avoid getting the last argument as that should be the aggregate method being used.
            $relations = is_array($relations) ? $relations : func_get_arg(func_num_args() - 1);

            foreach ($this->parseWithRelations($relations) as $name => $constraints) {
                // First we will determine if the name has been aliased using an "as" clause on the name
                // and if it has we will extract the actual relationship name and the desired name of
                // the resulting column. This allows multiple counts on the same relationship name.
                $segments = explode(' ', $name);

                unset($alias);

                if (count($segments) == 3 && Str::lower($segments[1]) == 'as') {
                    list($name, $alias) = [$segments[0], $segments[2]];
                }

                // Table and column are divided by a ':'. If one isn't provided, replace it
                if (Str::contains($name, ':')) {
                    list($name, $column) = explode(':', $name);
                } else {
                    $column = ($method == 'count') ? '*' : $this->model->getKeyName();
                }

                $relation = $this->getRelationWithoutConstraints($name);

                //
                $query = $relation->getRelationExistenceAggregateQuery(
                    $relation->getRelated()->newQuery(), $this, $method, $column
                );

                $query->callScope($constraints);

                $query->mergeConstraintsFrom($relation->getQuery());

                // Finally we will add the proper result column alias to the query and run the subselect
                // statement against the query builder. Then we will return the builder instance back
                // to the developer for further constraint chaining that needs to take place on it.
                $column = snake_case(isset($alias) ? $alias : $name) . '__' . ($column == '*' ? 'all' : $column) . '_' . $method;

                $this->selectSub($query->toBase(), $column);
            }

            return $this;
        });
    }
}