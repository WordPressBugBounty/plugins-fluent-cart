<?php

namespace FluentCart\App\Models\Relations;




use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Collection;
use FluentCart\Framework\Database\Orm\Model;
use FluentCart\Framework\Database\Orm\Relations\Relation;

class BundleChildrenRelation extends Relation
{
    /**
     * The JSON column name
     *
     * @var string
     */
    protected $jsonColumn;

    /**
     * The JSON key that contains child IDs
     *
     * @var string
     */
    protected $jsonKey;

    /**
     * Create a new BundleChildrenRelation instance.
     *
     * @param  Builder  $query
     * @param  Model  $parent
     * @param  string  $jsonColumn
     * @param  string  $jsonKey
     * @return void
     */
    public function __construct(Builder $query, Model $parent, $jsonColumn = 'other_info', $jsonKey = 'bundle_child_ids')
    {
        $this->jsonColumn = $jsonColumn;
        $this->jsonKey = $jsonKey;

        parent::__construct($query, $parent);
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {
        if (static::$constraints) {
            $childIds = $this->getChildIds();

            if (empty($childIds)) {
                // No child IDs, return empty result
                $this->query->whereRaw('1 = 0');
            } else {
                $this->query->whereIn($this->related->getKeyName(), $childIds);

                // Preserve the order of IDs
                $this->query->orderByRaw(
                    'FIELD(' . $this->related->getKeyName() . ', ' . implode(',', $childIds) . ')'
                );
            }
        }
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $allChildIds = (new Collection($models))->flatMap(function ($model) {
            return $this->getChildIdsFromModel($model);
        })->unique()->values()->all();

        if (empty($allChildIds)) {
            $this->query->whereRaw('1 = 0');
        } else {
            $this->query->whereIn($this->related->getKeyName(), $allChildIds);
        }
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array  $models
     * @param  string  $relation
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array  $models
     * @param  Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        if ($results->isEmpty()) {
            return $models;
        }

        // Create a dictionary of results keyed by ID
        $dictionary = $results->keyBy($this->related->getKeyName());

        // Match results to each parent model
        foreach ($models as $model) {
            $childIds = $this->getChildIdsFromModel($model);

            // Get the children in the correct order
            $children = (new Collection($childIds))
                ->map(function ($id) use ($dictionary) {
                    return $dictionary->get($id);
                })
                ->filter()
                ->values();

            $model->setRelation($relation, $this->related->newCollection($children->toArray()));
        }

        return $models;
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        if (is_null($this->parent->{$this->parent->getKeyName()})) {
            return $this->related->newCollection();
        }

        return $this->get();
    }

    /**
     * Get the child IDs from the parent model.
     *
     * @return array
     */
    protected function getChildIds()
    {
        return $this->getChildIdsFromModel($this->parent);
    }

    /**
     * Get child IDs from a specific model.
     *
     * @param  Model  $model
     * @return array
     */
    protected function getChildIdsFromModel(Model $model)
    {
        $jsonData = $model->{$this->jsonColumn};

        if (is_string($jsonData)) {
            $jsonData = json_decode($jsonData, true);
        }

        $childIds = $jsonData[$this->jsonKey] ?? [];

        // Ensure we have an array of integers
        return array_filter(array_map('intval', (array) $childIds));
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param  string  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $this->query->where($column, $operator, $value, $boolean);

        return $this;
    }
}