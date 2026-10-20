<?php
declare(strict_types=1);
namespace {
    function ptr_runtime_paused(): bool { return $GLOBALS['sas_lore_paused']; }
}
namespace ChimSwornAndScorned {
    final class Pg
    {
        public array $ledgers = [];
        public array $resolved = [];
        public bool $pauseOnResolve = false;
        private array $owners;
        public function __construct(private array $seeds)
        {
            $this->owners = array_values(array_unique(array_column($seeds, 'owner')));
            foreach ($this->owners as $index => $name) $this->ledgers[11 + $index] = emptyLedger('pt-lore');
        }
        public function all(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'AS ledger')) {
                return array_map(fn (int $index): array => ['id' => 11 + $index, 'npc_name' => $this->owners[$index],
                    'ledger' => json_encode($this->ledgers[11 + $index], JSON_THROW_ON_ERROR)], array_keys($this->owners));
            }
            \check(str_contains($sql, 'LIMIT 2'), 'only expected counterpart query reaches fake DB');
            $this->resolved[] = $params[0];
            if ($this->pauseOnResolve && count($this->resolved) === 1) $GLOBALS['sas_lore_paused'] = true;
            return [['id' => 90 + count($this->resolved)]];
        }
    }
    final class PerspectiveStore
    {
        public array $commits = [];
        public bool $pauseAfterFirst = true;
        public function __construct(private Pg $pg) {}
        public function commitLedger(array $request, callable $mutate): string
        {
            $id = $request['owner_npc_id'];
            $result = $mutate($this->pg->ledgers[$id]);
            \check($result['status'] === 'applied' && $result['affinity_delta'] === 0, 'actual lore seeds commit without affinity effects');
            $this->pg->ledgers[$id] = $result['ledger'];
            $this->commits[] = $id;
            if ($this->pauseAfterFirst && count($this->commits) === 1) $GLOBALS['sas_lore_paused'] = true;
            return 'committed';
        }
    }
    function pgTextArray(array $values): string { return json_encode($values, JSON_THROW_ON_ERROR); }
}
