<?php
/**
 * Minimal $wpdb for the review demo seed tests: records queries and returns
 * canned results for get_col() and query().
 */
namespace Wash\Tests\Unit;

class ReviewDemoWpdbStub
{
    public string $term_relationships = 'wp_term_relationships';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $posts = 'wp_posts';
    /** @var string[] */
    public array $queries = [];

    /** @param int[] $groups ids get_col() returns; $removed is what query() returns */
    public function __construct(private array $groups = [], private int $removed = 0)
    {
    }

    public function get_col(string $sql): array
    {
        $this->queries[] = $sql;
        return $this->groups;
    }

    public function query(string $sql): int
    {
        $this->queries[] = $sql;
        return $this->removed;
    }
}
