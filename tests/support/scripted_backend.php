<?php
declare(strict_types=1);

use ChimSwornAndScorned\DecisionBackend;

final class ScriptedBackend implements DecisionBackend
{
    /** @var list<list<string>> question ids asked, one list per call */
    public array $calls = [];

    public function __construct(private array $answers, private string $label = 'scripted')
    {
    }

    public function name(): string
    {
        return $this->label;
    }

    public function ask(array $state, array $questions): mixed
    {
        $ids = array_column($questions, 'id');
        $this->calls[] = $ids;
        return array_intersect_key($this->answers, array_flip($ids));
    }
}
