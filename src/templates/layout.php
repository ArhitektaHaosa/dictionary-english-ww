<?php
/** @var string $siteName @var string $title @var string $description @var string $canonical @var string $content @var string $nonce @var string $year */
$robots = $robots ?? 'index,follow';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<meta name="description" content="<?= h($description) ?>">
<meta name="robots" content="<?= h($robots) ?>">
<link rel="canonical" href="<?= h($canonical) ?>">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/site.css">
</head>
<body>
<a class="skip" href="#content">Skip to content</a>
<header class="mast">
  <div class="mast-inner">
    <a class="brand" href="/">
      <span class="mark" aria-hidden="true">WW</span>
      <span class="brand-text">
        <strong><?= h($siteName) ?></strong>
        <em>English</em>
      </span>
    </a>
    <form class="lookup" action="/search" method="get" role="search">
      <label class="sr" for="q">Look up a word</label>
      <input id="q" name="q" type="search" maxlength="80" placeholder="Look up a word" data-suggest autocomplete="off" enterkeyhint="search" value="<?= h($query ?? '') ?>">
      <button type="submit">Look up</button>
      <div class="suggest" data-suggest-list hidden></div>
    </form>
  </div>
</header>
<main id="content">
<?= $content ?>
</main>
<footer class="colophon">
  <p><?= h($siteName) ?> is an independent English dictionary. It is not affiliated with Collins or any other publisher. Definitions are not copied from proprietary dictionaries.</p>
  <ul>
    <li><a href="https://kaikki.org/dictionary/English/index.html">Wiktionary via Kaikki.org</a> — CC BY-SA 4.0</li>
    <li><a href="https://en-word.net/">Open English WordNet</a> — CC BY 4.0</li>
    <li><a href="https://www.gutenberg.org/ebooks/3202">Moby Thesaurus</a> — public domain</li>
    <li><a href="https://tatoeba.org/">Tatoeba</a> — CC BY 2.0 FR</li>
    <li><a href="https://github.com/cmusphinx/cmudict">CMU Pronouncing Dictionary</a> — BSD-style</li>
    <li><a href="https://github.com/hermitdave/FrequencyWords">FrequencyWords</a> (OpenSubtitles 2018) — CC BY-SA 4.0</li>
  </ul>
  <p>Code is <a href="https://opensource.org/license/mit">MIT</a>. See NOTICE for the data licences. <?= h($year) ?>.</p>
</footer>
<script src="/assets/js/suggest.js" defer></script>
</body>
</html>
