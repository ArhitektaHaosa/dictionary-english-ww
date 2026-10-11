<?php

declare(strict_types=1);

final class App
{
    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {
    }

    public function run(): void
    {
        $nonce = Http::nonce();
        Http::securityHeaders($nonce);
        header('Content-Type: text/html; charset=UTF-8');
        if (!Http::wantsHtml()) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            echo 'Method not allowed';
            return;
        }
        $view = new View($this->root, $this->config, $nonce);
        if (empty($this->config['configured'])) {
            $view->message(503, 'Setup required', 'Copy config.sample.php to config.php and build the database with tools/import.php.');
            return;
        }
        try {
            $pdo = Database::openReadOnly((string) $this->config['db_path']);
        } catch (Throwable $error) {
            error_log('dictionary-ww: ' . $error->getMessage());
            $view->message(503, 'Dictionary unavailable', 'unable to open database');
            return;
        }
        $repo = new DictionaryRepository($pdo);
        $path = Http::path();
        if ($path === '/') {
            $view->home($repo);
            return;
        }
        if ($path === '/search') {
            $this->search($repo, $view);
            return;
        }
        if ($path === '/browse') {
            $view->browseIndex();
            return;
        }
        if (preg_match('#^/browse/([a-z0-9])$#', $path, $match) === 1) {
            $after = isset($_GET['after']) ? (string) $_GET['after'] : '';
            if (strlen($after) > 80) {
                $after = '';
            }
            $view->browse($match[1], $repo->browse($match[1], $after));
            return;
        }
        if (preg_match('#^/dictionary/english/([^/]+)$#u', $path, $match) === 1) {
            $slug = $match[1];
            if (!Slug::isValid($slug)) {
                $view->notFound($repo, '');
                return;
            }
            $entry = $repo->entry($slug);
            if ($entry === null) {
                $view->notFound($repo, $slug);
                return;
            }
            $view->entry($entry);
            return;
        }
        $view->notFound($repo, '');
    }

    private function search(DictionaryRepository $repo, View $view): void
    {
        $limit = new RateLimit(
            $this->root . '/data/ratelimit',
            (int) $this->config['search_per_minute'],
        );
        if (!$limit->allow('search')) {
            header('Retry-After: 60');
            $view->message(429, 'Slow down', 'Too many searches from this address. Wait a minute and try again.');
            return;
        }
        $query = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($query) > 80) {
            $query = mb_substr($query, 0, 80);
        }
        if ($query === '') {
            $view->search('', []);
            return;
        }
        $slug = Slug::fromHeadword($query);
        if ($slug !== null && $repo->exists($slug)) {
            header('Location: /dictionary/english/' . rawurlencode($slug), true, 302);
            return;
        }
        $view->search($query, $repo->search($query, 20));
    }
}
