<?php

declare(strict_types=1);

final class View
{
    public function __construct(
        private readonly string $root,
        private readonly array $config,
        private readonly string $nonce,
    ) {
    }

    public function home(DictionaryRepository $repo): void
    {
        $this->page('home', [
            'title' => (string) $this->config['site_name'],
            'description' => 'An open English dictionary: numbered senses, pronunciation, and the words on either side.',
            'canonical' => $this->url('/'),
            'word' => $repo->wordOfTheDay(gmdate('Y-m-d')),
        ]);
    }

    /** @param array<string, mixed> $entry */
    public function entry(array $entry): void
    {
        $gloss = (string) ($entry['entries'][0]['senses'][0]['gloss'] ?? '');
        $this->page('entry', [
            'title' => $entry['headword'] . ' — ' . $this->config['site_name'],
            'description' => $gloss !== '' ? $gloss : ('Definition of ' . $entry['headword']),
            'canonical' => $this->url('/dictionary/english/' . rawurlencode((string) $entry['slug'])),
            'entry' => $entry,
            'jsonLd' => $this->jsonLd($entry, $gloss),
        ]);
    }

    /** @param list<array{slug:string,headword:string}> $results */
    public function search(string $query, array $results): void
    {
        $this->page('search', [
            'title' => 'Search — ' . $this->config['site_name'],
            'description' => 'Search the open English dictionary.',
            'canonical' => $this->url('/search'),
            'query' => $query,
            'results' => $results,
            'robots' => 'noindex',
        ]);
    }

    public function browseIndex(): void
    {
        $this->page('browse', [
            'title' => 'A to Z — ' . $this->config['site_name'],
            'description' => 'Browse English headwords alphabetically.',
            'canonical' => $this->url('/browse'),
            'letter' => null,
            'rows' => [],
            'next' => null,
        ]);
    }

    /** @param array{rows:list<array{slug:string,headword:string}>,next:?string} $page */
    public function browse(string $letter, array $page): void
    {
        $label = $letter === '0' ? '0–9' : strtoupper($letter);
        $this->page('browse', [
            'title' => $label . ' — ' . $this->config['site_name'],
            'description' => 'English headwords starting with ' . $label . '.',
            'canonical' => $this->url('/browse/' . $letter),
            'letter' => $letter,
            'rows' => $page['rows'],
            'next' => $page['next'],
        ]);
    }

    public function notFound(DictionaryRepository $repo, string $attempt): void
    {
        $suggestions = $attempt !== '' ? $repo->missingSuggestions($attempt, 8) : [];
        $this->page('not_found', [
            'title' => 'Not found — ' . $this->config['site_name'],
            'description' => 'That headword is not in this dictionary.',
            'canonical' => $this->url('/'),
            'attempt' => $attempt,
            'suggestions' => $suggestions,
            'robots' => 'noindex',
        ], 404);
    }

    public function message(int $status, string $title, string $body): void
    {
        $this->page('message', [
            'title' => $title,
            'description' => $body,
            'canonical' => $this->url('/'),
            'message' => $body,
            'robots' => 'noindex',
        ], $status);
    }

    public function url(string $path): string
    {
        return rtrim((string) $this->config['base_url'], '/') . $path;
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data, int $status = 200): void
    {
        http_response_code($status);
        $data['siteName'] = (string) $this->config['site_name'];
        $data['nonce'] = $this->nonce;
        $data['year'] = gmdate('Y');
        $data['content'] = $this->capture($template, $data);
        extract($data, EXTR_SKIP);
        require $this->root . '/src/templates/layout.php';
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $this->root . '/src/templates/' . $template . '.php';
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $entry */
    private function jsonLd(array $entry, string $gloss): string
    {
        $payload = [
            '@context' => 'https://schema.org',
            '@type' => 'DefinedTerm',
            'name' => $entry['headword'],
            'description' => $gloss,
            'inDefinedTermSet' => $this->url('/dictionary/english'),
            'url' => $this->url('/dictionary/english/' . rawurlencode((string) $entry['slug'])),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return is_string($json) ? $json : '{}';
    }
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function frequency_marks(int $band): string
{
    $html = '<span class="freq" aria-label="Frequency ' . $band . ' of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<i class="' . ($i <= $band ? 'freq-on' : 'freq-off') . '"></i>';
    }
    return $html . '</span>';
}

/** @param array{target:string,slug:?string} $item */
function word_link(array $item): string
{
    $label = h($item['target']);
    if ($item['slug'] === null) {
        return '<span>' . $label . '</span>';
    }
    return '<a href="/dictionary/english/' . h(rawurlencode($item['slug'])) . '">' . $label . '</a>';
}

function frequency_label(int $band): string
{
    return match ($band) {
        5 => 'among the most common words',
        4 => 'very common',
        3 => 'common',
        2 => 'familiar',
        1 => 'less common',
        default => '',
    };
}

function example_credit(?string $source): string
{
    if ($source === null || $source === '' || $source === 'wiktionary') {
        return '';
    }
    if (preg_match('/^tatoeba:(\d+)$/', $source, $match) === 1) {
        $id = $match[1];
        return '<a class="credit" href="https://tatoeba.org/en/sentences/show/' . h($id) . '">Tatoeba ' . h($id) . '</a>';
    }
    return '<span class="credit">' . h($source) . '</span>';
}
