<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Smart QMS') ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?= $content ?>
<script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>"></script>
<?= $scripts ?? '' ?>
</body>
</html>
