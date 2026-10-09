<?php

/*
 * Just enough of OPNsense for Lens's own controllers to run unchanged.
 *
 * The controllers do input and output only (DESIGN §4.21), so the framework
 * they touch is small: a request, configd, and config.xml. Each is replaced
 * here by the thing it stands for -- configd by the real collector against a
 * seeded store (commands built from actions_lens.conf exactly as configd would
 * build them), core's own commands by recorded or plausible fixtures, and
 * config.xml by a fixture shaped like the operator's box. Nothing in
 * net-mgmt/lens is touched to make it work.
 */

namespace {
    if (!function_exists('gettext')) {
        /* core's PHP has the gettext extension; a laptop's may not -- the untranslated string, as in the tests */
        function gettext($message)
        {
            return $message;
        }
    }

    class PreviewRequest
    {
        private $body = null;

        public function get($name = null, $filter = null, $default = null)
        {
            return $name === null ? $_GET : ($_GET[$name] ?? $default);
        }

        public function getPost($name = null, $filter = null, $default = null)
        {
            if ($this->body === null) {
                $decoded = json_decode((string)file_get_contents('php://input'), true);
                $this->body = is_array($decoded) ? $decoded : $_POST;
            }
            return $name === null ? $this->body : ($this->body[$name] ?? $default);
        }

        public function isPost()
        {
            return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        }

        /* LENS_PREVIEW_CLIENT sits the browser at a seeded device's address,
           so the pause guard (§4.83) can be seen deciding */
        public function getClientAddress()
        {
            return getenv('LENS_PREVIEW_CLIENT') ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        }
    }

    class PreviewResponse
    {
        public $headers = [];

        public function setRawHeader($header)
        {
            $this->headers[] = $header;
        }

        public function setContent($content)
        {
            echo $content;
        }
    }
}

namespace OPNsense\Base {
    class ApiControllerBase
    {
        public $request;
        public $response;

        public function __construct()
        {
            $this->request = new \PreviewRequest();
            $this->response = new \PreviewResponse();
        }

        public function getUserName()
        {
            return 'root';
        }
    }

    class IndexController
    {
    }
}

namespace OPNsense\Core {
    /* the preview's user is root: every page is accessible, as on a fresh box */
    class ACL
    {
        public function isPageAccessible($username, $url)
        {
            return true;
        }

        public function hasPrivilege($username, $reqpriv)
        {
            return true;
        }
    }

    class Backend
    {
        public function configdpRun($event, $params = [], $detach = false)
        {
            foreach ((array)$params as $param) {
                $event .= ' ' . escapeshellarg($param ?? '');
            }
            return $this->configdRun($event);
        }

        public function configdRun($event, $detach = false)
        {
            $words = \PreviewConfigd::split($event);
            if (($words[0] ?? '') === 'lens') {
                return \PreviewConfigd::lens(array_slice($words, 1));
            }
            return \PreviewConfigd::core($words);
        }
    }

    class Config
    {
        private static $instance = null;
        private $xml;

        public static function getInstance()
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function object()
        {
            if ($this->xml === null) {
                $this->xml = simplexml_load_file(PREVIEW_FIXTURES . '/config.xml');
                /* the operator's second box: Unbound resolving and recording (§4.64) */
                if (getenv('LENS_PREVIEW_UNBOUND') === '1') {
                    $this->xml->OPNsense->unboundplus->general->enabled = '1';
                    $this->xml->OPNsense->unboundplus->general->stats = '1';
                }
            }
            return $this->xml;
        }

        public function save($revision = null)
        {
        }

        public function lock()
        {
            return $this;
        }

        public function unlock()
        {
            return $this;
        }
    }
}

/*
 * Stand-ins for core's Alias and Filter models, just enough for PauseRule
 * (§4.74): items with fields, Add, del, setNodes, validation that finds
 * nothing. Kept in firewall.json beside the seeded store, so a pause made in
 * the preview survives the next request. They prove the page, not core: what
 * core's real models do with the same calls is the router round's question.
 */
namespace OPNsense\Firewall {
    class PreviewNode
    {
        public $__reference;
        private $fields;
        private $uuid;

        public function __construct(string $uuid, array &$fields, string $reference)
        {
            $this->uuid = $uuid;
            $this->fields = &$fields;
            $this->__reference = $reference . '.' . $uuid;
        }

        public function __get($name)
        {
            return (string)($this->fields[$name] ?? '');
        }

        public function setNodes(array $values)
        {
            foreach ($values as $key => $value) {
                $this->fields[$key] = (string)$value;
            }
        }

        public function getAttribute($name)
        {
            return $name === 'uuid' ? $this->uuid : null;
        }
    }

    class PreviewItems
    {
        private $items;
        private $reference;

        public function __construct(array &$items, string $reference)
        {
            $this->items = &$items;
            $this->reference = $reference;
        }

        public function iterateItems()
        {
            foreach (array_keys($this->items) as $uuid) {
                yield $uuid => new PreviewNode($uuid, $this->items[$uuid], $this->reference);
            }
        }

        public function Add()
        {
            $uuid = sprintf('%08x-0000-4000-8000-%012x', mt_rand(), mt_rand());
            $this->items[$uuid] = [];
            return new PreviewNode($uuid, $this->items[$uuid], $this->reference);
        }

        public function del($uuid)
        {
            unset($this->items[$uuid]);
        }

        public function __get($uuid)
        {
            return new PreviewNode($uuid, $this->items[$uuid], $this->reference);
        }
    }

    class PreviewModel
    {
        protected static function file(): string
        {
            return dirname(PREVIEW_DB) . '/firewall.json';
        }

        protected $data;

        public function __construct()
        {
            $read = json_decode((string)@file_get_contents(self::file()), true);
            $this->data = is_array($read) ? $read : ['aliases' => [], 'rules' => []];
        }

        public function performValidation()
        {
            return [];
        }

        public function serializeToConfig()
        {
            $all = json_decode((string)@file_get_contents(self::file()), true) ?: ['aliases' => [], 'rules' => []];
            $all[static::KEY] = $this->data[static::KEY];
            file_put_contents(self::file(), json_encode($all, JSON_PRETTY_PRINT));
        }
    }

    class Alias extends PreviewModel
    {
        const KEY = 'aliases';
        public $aliases;

        public function __construct()
        {
            parent::__construct();
            $this->aliases = new \stdClass();
            $this->aliases->alias = new PreviewItems($this->data['aliases'], 'aliases.alias');
        }
    }

    /* §4.85: the category "Lens" that groups the alias and the rule */
    class Category extends PreviewModel
    {
        const KEY = 'categories';
        public $categories;

        public function __construct()
        {
            parent::__construct();
            $this->data['categories'] = $this->data['categories'] ?? [];
            $this->categories = new \stdClass();
            $this->categories->category = new PreviewItems($this->data['categories'], 'categories.category');
        }
    }

    class Filter extends PreviewModel
    {
        const KEY = 'rules';
        public $rules;

        public function __construct()
        {
            parent::__construct();
            $this->rules = new \stdClass();
            $this->rules->rule = new PreviewItems($this->data['rules'], 'rules.rule');
        }
    }
}

namespace {
    class PreviewConfigd
    {
        /** configd's own word split: spaces, with shell single quotes honoured */
        public static function split(string $event): array
        {
            preg_match_all("/'((?:[^']|'\\\\'')*)'|(\\S+)/", $event, $m, PREG_SET_ORDER);
            $words = [];
            foreach ($m as $match) {
                $words[] = isset($match[2]) && $match[2] !== ''
                    ? $match[2] : str_replace("'\\''", "'", $match[1]);
            }
            return $words;
        }

        private static function below(array $actions, string $prefix): bool
        {
            foreach (array_keys($actions) as $section) {
                if (strpos($section, $prefix . '.') === 0) {
                    return true;
                }
            }
            return false;
        }

        /** an action from actions_lens.conf, built and run the way configd does */
        public static function lens(array $words): string
        {
            $actions = parse_ini_string(
                /* configd's RawConfigParser takes '#' comments; PHP's ini reader does not */
                preg_replace(
                    ['/^#.*$/m', '/^([a-z_]+):/m'],
                    ['', '$1='],
                    (string)file_get_contents(PREVIEW_LENS . '/service/conf/actions.d/actions_lens.conf')
                ),
                true,
                INI_SCANNER_RAW
            );
            /* configd reads dotted sections as a tree: "dns device x" is [dns.device] */
            $name = array_shift($words);
            while (!isset($actions[$name]) && $words !== [] && self::below($actions, $name)) {
                $name .= '.' . array_shift($words);
            }
            if (!isset($actions[$name])) {
                return 'Action not found';
            }
            $action = $actions[$name];
            $command = str_replace(
                '/usr/local/opnsense/scripts/lens/collect.py',
                'python3 ' . escapeshellarg(PREVIEW_LENS . '/scripts/lens/collect.py'),
                $action['command']
            );
            $parameters = (string)($action['parameters'] ?? '');
            while (strpos($parameters, '%s') !== false) {
                $parameters = preg_replace('/%s/', escapeshellarg((string)array_shift($words)), $parameters, 1);
            }

            $env = 'LENS_DB=' . escapeshellarg(PREVIEW_DB) . ' '
                . 'LENS_UNBOUND_STATS=' . escapeshellarg(__DIR__ . '/unbound_stats.py') . ' ';
            /* the second box's query store, read through core's own helper (§4.70) */
            if (getenv('LENS_PREVIEW_UNBOUND') === '1') {
                $env .= 'LENS_UNBOUND_DB=' . escapeshellarg(dirname(PREVIEW_DB) . '/unbound.duckdb') . ' '
                    . 'LENS_SITE_PYTHON=' . escapeshellarg(getenv('OPNSENSE_CORE') . '/src/opnsense/site-python') . ' ';
            }
            exec($env . $command . ' ' . $parameters . ' 2>/dev/null', $out, $code);
            $output = implode("\n", $out) . "\n";

            if (($action['type'] ?? '') === 'script') {
                return $code === 0 ? "OK\n" : sprintf('Error (%d)', $code);
            }
            if ($code !== 0 && strtolower(trim($action['errors'] ?? '')) !== 'no') {
                return 'Execute error';
            }
            return $output;
        }

        /** core's commands: a fixture, or something that moves like the real thing */
        public static function core(array $words): string
        {
            $event = implode(' ', $words);
            $now = microtime(true);

            if ($event === 'interface show traffic') {
                /* counters that grow, so two readings five seconds apart give a rate */
                return json_encode(['time' => $now, 'interfaces' => ['wan' => [
                    'name' => 'WAN',
                    'bytes received' => (int)($now * 2.9e6) % PHP_INT_MAX,
                    'bytes transmitted' => (int)($now * 4.1e5) % PHP_INT_MAX,
                ]]]);
            }
            if (strpos($event, 'system sysctl values') === 0 && strpos($event, 'temperature') !== false) {
                /* the sensors `system sensors` named (fixtures/system-sensors.txt) */
                return json_encode(['dev.cpu.0.temperature' => '47.0C', 'dev.cpu.1.temperature' => '49.0C']);
            }
            if (strpos($event, 'system sysctl values') === 0) {
                return json_encode([
                    'kern.boottime' => sprintf('{ sec = %d, usec = 0 }', (int)$now - 6 * 86400 - 5 * 3600),
                    'vm.loadavg' => '{ 0.42 0.51 0.48 }',
                    'kern.smp.cpus' => '2',
                    'hw.physmem' => '4294967296',
                    'vm.stats.vm.v_page_count' => '1000000',
                    'vm.stats.vm.v_inactive_count' => '260000',
                    'vm.stats.vm.v_cache_count' => '0',
                    'vm.stats.vm.v_laundry_count' => '30000',
                    'vm.stats.vm.v_free_count' => '220000',
                ]);
            }

            /* core passes [null] for "every interface"; the fixture is the same either way */
            foreach (['interface list ifconfig', 'interface list stats'] as $all) {
                if (strpos($event, $all) === 0) {
                    $event = $all;
                }
            }
            $file = PREVIEW_FIXTURES . '/' . preg_replace('/[^a-z0-9]+/', '-', $event) . '.json';
            if (is_file($file)) {
                return (string)file_get_contents($file);
            }
            $file = PREVIEW_FIXTURES . '/' . preg_replace('/[^a-z0-9]+/', '-', $event) . '.txt';
            return is_file($file) ? (string)file_get_contents($file) : '';
        }
    }
}
