<?php

declare(strict_types=1);

// Render production gallery templates without booting the application or accessing a database.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$theme = $argv[1] ?? 'default';
$locale = $argv[2] ?? 'en';
$mode = $argv[3] ?? 'light';
if (! in_array($theme, ['default', 'bootstrap'], true) || ! in_array($locale, ['en', 'ar'], true) || ! in_array($mode, ['light', 'dark'], true)) {
    throw new InvalidArgumentException('Unsupported gallery fixture variant');
}
$files = new Twig\Loader\FilesystemLoader();
if ($theme === 'bootstrap') {
    $files->addPath($root.'/views/themes/bootstrap');
}
$files->addPath($root.'/src/Modules/Users/views');
$files->addPath($root.'/views/themes/default');
$files->addPath($root.'/views/themes/default', 'default');
$base = <<<'TWIG'
<!doctype html><html lang="{{ fixtureLocale }}" dir="{{ fixtureLocale == 'ar' ? 'rtl' : 'ltr' }}" data-color-mode="{{ fixtureMode }}" data-bs-theme="{{ fixtureMode }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ title }}</title>
<link rel="stylesheet" href="/assets/css/base.css"><link rel="stylesheet" href="/assets/css/site.css">
{% if fixtureTheme == 'bootstrap' %}<link rel="stylesheet" href="/assets/css/{{ fixtureLocale == 'ar' ? 'bootstrap-rtl' : 'bootstrap' }}.css"><link rel="stylesheet" href="/assets/css/bootstrap-skin.css">{% endif %}
</head><body>{% block layout_main %}{% endblock %}
<script>window.CMS={api:async(url,options={})=>{const r=await fetch(url,{method:options.method||'GET',headers:{'Content-Type':'application/json'},body:options.data?JSON.stringify(options.data):undefined});if(!r.ok)throw new Error('Fixture request failed');return r.status===204?undefined:r.json()},toast:()=>{},confirm:()=>{}};</script>
{% if fixtureTheme == 'bootstrap' %}<script type="module" src="/assets/js/ui-bootstrap.js"></script>{% endif %}
<script type="module" src="/assets/js/users.js"></script></body></html>
TWIG;
$twig = new Twig\Environment(new Twig\Loader\ChainLoader([
    new Twig\Loader\ArrayLoader(['layouts/base.twig' => $base, 'components/widgets/block.twig' => '',
        'fixture-sidebar.twig' => "{% extends 'layouts/single.twig' %}{% block content %}<h1>{{ title }}</h1><aside style=\"max-width:300px\">{% include 'components/users/photos-link.twig' %}</aside>{% endblock %}"]),
    $files,
]), ['autoescape' => 'html', 'strict_variables' => true]);
$tm = new StreamEngine\Core\TranslationManager($locale, 'en');
$twig->addFunction(new Twig\TwigFunction('trans', $tm->trans(...)));
foreach (['fixtureTheme' => $theme, 'fixtureLocale' => $locale, 'fixtureMode' => $mode] as $key => $value) {
    $twig->addGlobal($key, $value);
}
$data = [
    'photoOwner' => ['name' => 'Alice', 'url' => '/users/alice/', 'avatar' => '/image/avatar'],
    'title' => 'Summer', 'album' => ['title' => 'Summer', 'description' => 'Photographs from our summer trip.', 'visibility' => 'public'],
    'photo' => null, 'isPhotoForm' => false, 'isAlbumForm' => false, 'isManage' => false,
    'newPhotoUrl' => '/album/edit/#album-upload', 'albumUrl' => '/album/', 'editAlbumUrl' => '/album/edit/',
    'itemApiUrl' => '/api/album', 'photoUploadApiUrl' => '/api/album/photos', 'listUrl' => '/albums/',
    'canEditAlbum' => true, 'canCreate' => false, 'isCommunityGallery' => false, 'coverUrl' => null,
    'previousUrl' => null, 'nextUrl' => null, 'photoComments' => null, 'photoCommentCount' => 2,
    'photoCommentGuest' => false, 'photoRatingGuest' => false, 'photoCanRate' => true,
    'photoUserRating' => 3, 'photoRatingUrl' => '/api/photos/1/rating', 'photoCount' => 4,
    'photoPosition' => null, 'previousPhotoUrl' => null, 'nextPhotoUrl' => null, 'nextPhotoPreviewUrl' => null,
    'photoReturnUrl' => '/album/',
];
$data['photoItems'] = array_map(fn ($i) => ['slug' => (string) $i, 'title' => "Photo $i", 'caption' => 'A summer memory', 'url' => "/photo/$i/", 'previewUrl' => "/image/$i", 'cover' => $i === 1], range(1, 4));
$pages = [];
$render = static fn (array $overrides = []) => $twig->render('modules/users/photos.twig', array_replace($data, $overrides));
$pages['/album/'] = $render();
$pages['/album/edit/'] = $render(['isManage' => true]);
$pages['/albums/new/'] = $render(['isAlbumForm' => true, 'album' => null, 'canCreate' => true, 'apiUrl' => '/api/albums', 'title' => 'New album']);
$albums = array_map(fn ($i) => ['title' => "Album $i", 'description' => 'A summer trip', 'photoCount' => $i === 3 ? 0 : 20, 'previewUrl' => $i === 3 ? null : "/image/$i", 'url' => '/album/'], range(1, 3));
$pages['/albums/'] = $render(['album' => null, 'albums' => $albums]);
foreach (range(1, 4) as $n) {
    $photo = ['title' => "Photo $n", 'description' => 'A summer memory', 'canonicalUrl' => "/photo/$n/", 'ratingCount' => 2, 'ratingAverage' => 3.5];
    $fields = ['photo' => $photo, 'photoPosition' => $n, 'previousPhotoUrl' => $n > 1 ? '/photo/'.($n - 1).'/' : null, 'nextPhotoUrl' => $n < 4 ? '/photo/'.($n + 1).'/' : null, 'nextPhotoPreviewUrl' => $n < 4 ? '/image/'.($n + 1) : null, 'photoPreviewUrl' => "/image/$n", 'photoOriginalUrl' => "/original/$n"];
    $feed = ['id' => 500, 'parentId' => 100 + $n, 'authorDisplayName' => 'Maria', 'authorAvatarUrl' => '/image/avatar', 'createdAtLabel' => 'Today', 'content' => '<p>Beautiful photograph!</p>'];
    $comment = ['feed' => $feed, 'canEdit' => true, 'canDelete' => true, 'replies' => [], 'nextReplies' => null];
    $discussion = ['rootId' => 100 + $n, 'parentId' => 100 + $n, 'items' => [$comment], 'cursor' => 'more', 'isReplyPage' => false];
    $pages["/photo/$n/"] = $render($fields);
    $pages["/photo/$n/?discussion=1"] = $render([...$fields, 'photoComments' => $discussion]);
    $pages["/guest/$n/"] = $render([...$fields, 'photoCanRate' => false, 'photoRatingGuest' => true, 'photoCommentGuest' => true]);
    $guestDiscussion = [...$discussion, 'items' => [[...$comment, 'canEdit' => false, 'canDelete' => false]]];
    $pages["/guest/$n/?discussion=1"] = $render([...$fields, 'photoCanRate' => false, 'photoRatingGuest' => true, 'photoCommentGuest' => true, 'photoComments' => $guestDiscussion]);
}
$photos = array_map(fn ($i) => ['title' => "Photo $i", 'url' => "/photo/$i/", 'previewUrl' => "/image/$i", 'authorName' => 'Alice', 'authorUrl' => '/users/alice/', 'ratingAverage' => $i === 3 ? 0 : 4.5, 'ratingCount' => $i === 3 ? 0 : 2], range(1, 4));
foreach ([false, true] as $ranked) {
    $pages['/showcase/'.($ranked ? '?sort=best' : '')] = $twig->render('modules/users/photo-showcase.twig', ['title' => 'Photographs', 'photos' => $ranked ? array_filter($photos, fn ($p) => $p['ratingCount'] > 0) : $photos, 'ranked' => $ranked, 'latestUrl' => '/showcase/', 'bestUrl' => '/showcase/?sort=best', 'previousUrl' => null, 'nextUrl' => '/showcase/?'.($ranked ? 'sort=best&' : '').'offset=24']);
}
foreach ([0, 1, 2, 3] as $n) {
    $pages["/sidebar/$n/"] = $twig->render('fixture-sidebar.twig', ['title' => 'Photographs', 'photosUrl' => '/albums/', 'photosPreview' => ['count' => $n ? 128 : 0, 'previews' => $n ? array_map(fn ($i) => "/image/$i", range(1, $n)) : []]]);
}
echo json_encode($pages, JSON_THROW_ON_ERROR);
