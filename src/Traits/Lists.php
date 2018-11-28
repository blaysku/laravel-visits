<?php

namespace awssat\Visits\Traits;

trait Lists
{
    /**
     * Fetch all time trending subjects.
     *
     * @param int $limit
     * @param callable|null $callback
     * @param bool $isLow
     * @return \Illuminate\Support\Collection|array
     */
    public function top($limit = 5, callable $callback = null, $isLow = false)
    {
        $cacheKey = $this->keys->cache($limit, $isLow);
        $cachedList = $this->cachedList($limit, $cacheKey);
        $visitsIds = $this->getVisitsIds($limit, $this->keys->visits, $isLow);

        if ($visitsIds === $cachedList->pluck($this->keys->primary)->toArray() && ! $this->fresh) {
            return $cachedList;
        }

        return $this->freshList($cacheKey, $visitsIds, $callback);
    }


    /**
     * Top/low countries
     *
     * @param int $limit
     * @param bool $isLow
     * @return mixed
     */
    public function countries($limit = -1, $isLow = false)
    {
        $range = $isLow ? 'zrange' : 'zrevrange';

        return $this->redis->$range($this->keys->visits . "_countries:{$this->keys->id}", 0, $limit, 'WITHSCORES');
    }

    /**
     * top/lows refs
     *
     * @param int $limit
     * @param bool $isLow
     * @return mixed
     */
    public function refs($limit = -1, $isLow = false)
    {
        $range = $isLow ? 'zrange' : 'zrevrange';

        return $this->redis->$range($this->keys->visits . "_referers:{$this->keys->id}", 0, $limit, 'WITHSCORES');
    }

    /**
     * Fetch lowest subjects.
     *
     * @param int $limit
     * @param callable|null $callback
     * @return \Illuminate\Support\Collection|array
     */
    public function low($limit = 5, callable $callback = null)
    {
        return $this->top($limit, $callback, true);
    }


    /**
     * @param $limit
     * @param $visitsKey
     * @param bool $isLow
     * @return mixed
     */
    protected function getVisitsIds($limit, $visitsKey, $isLow = false)
    {
        $range = $isLow ? 'zrange' : 'zrevrange';

        return array_map('intval', $this->redis->$range($visitsKey, 0, $limit - 1));
    }

    /**
     * @param $cacheKey
     * @param $visitsIds
     * @param callable $callback
     * @return mixed
     */
    protected function freshList($cacheKey, $visitsIds, callable $callback = null)
    {
        if (count($visitsIds)) {
            $this->redis->del($cacheKey);

            $query = ($this->subject)::whereIn($this->keys->primary, $visitsIds);

            if (!is_null($callback)) {
                $query = call_user_func($callback, $query);
            };

            return $query->get()
                ->sortBy(function ($subject) use ($visitsIds) {
                    return array_search($subject->{$this->keys->primary}, $visitsIds);
                })
                ->values()
                ->each(function ($subject) use ($cacheKey) {
                    $this->redis->rpush($cacheKey, serialize($subject));
                });
        }

        return [];
    }

    /**
     * @param $limit
     * @param $cacheKey
     * @return \Illuminate\Support\Collection|array
     */
    protected function cachedList($limit, $cacheKey)
    {
        return collect(
            array_map('unserialize', $this->redis->lrange($cacheKey, 0, $limit - 1))
        );
    }
}
