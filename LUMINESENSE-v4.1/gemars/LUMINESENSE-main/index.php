<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--Bootstrap and JS CDN-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>

    <!--CSS files-->
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/containers.css">
    <link rel="stylesheet" href="css/landing.css">

    <title>LumineSense</title>
</head>

<body>
    <div class="parent-container">
        <div id="landing" class="child-container zoom-animation">
            <img src="images/logo.png" alt="LumineSense Logo">

            <!--
                CHANGE from original:
                Buttons now link directly with <a> tags pointing to the pages folder.
                The dissolve() JS animation still works because animations.js is loaded below.
            -->
            <button class="medium" onclick="dissolve('php/pages/faculty-login.php', 1)">Faculty</button>
            <button class="medium" onclick="dissolve('php/pages/admin-login.php', 1)">Administrator</button>
        </div>
    </div>

    <script src="script/animations.js"></script>
</body>

</html>
