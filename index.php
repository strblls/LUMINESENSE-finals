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
    <link rel="icon" type="image/png" sizes="32x32" href="images/icon.png">
    <link rel="shortcut icon" type="image/png" href="images/icon.png">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="css/containers.css">
    <link rel="stylesheet" href="css/landing.css">

    <title>LumineSense</title>
    <style>
        .bg-base {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            pointer-events: none;
        }

        .bg-hover {
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .bg-hover.active {
            opacity: 1;
        }

        .border-glow {
            border: 18px solid rgba(255, 255, 255, 0.6);
        }

        .tagline {
            color: #FFF;
            text-shadow: 4px 4px 9px #2F004F;
            font-family: 'Poppins', sans-serif;
            font-size: 40px;
            font-style: normal;
            font-weight: 900;
            line-height: 125.528%;
            pointer-events: none;
        }

        .subtagline {
            color: #FFF;
            text-shadow: 3px 3px 3px #2F004F;
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-style: normal;
            font-weight: 300;
            line-height: 125.528%;
            pointer-events: none;
        }

        .content-default,
        .content-hover,
        .border-glow,
        .border-gradient {
            transition: clip-path 0.6s cubic-bezier(0.65, 0, 0.35, 1);
        }

        .content-default,
        .border-glow {
            clip-path: circle(150% at 0 0);
        }

        .content-hover,
        .border-gradient {
            clip-path: circle(0% at 0 0);
        }

        .bg-hover.active~.tagline-wrapper .content-default,
        .bg-hover.active~.border-glow {
            clip-path: circle(0% at 0 0);
        }

        .bg-hover.active~.tagline-wrapper .content-hover,
        .bg-hover.active~.border-gradient {
            clip-path: circle(150% at 0 0);
        }

        .border-gradient {
            background: linear-gradient(to bottom, #ffcc00, #f0cc5e, #611bb2);
            border: none;
            padding: 18px;
            -webkit-mask-image:
                linear-gradient(#000, #000),
                linear-gradient(#000, #000);
            -webkit-mask-clip: content-box, border-box;
            -webkit-mask-composite: xor;
            mask-image:
                linear-gradient(#000, #000),
                linear-gradient(#000, #000);
            mask-clip: content-box, border-box;
            mask-composite: exclude;
        }
    </style>
</head>

<body style="background-color: transparent;">
    <div class="parent-container-index" style="position: relative; overflow: hidden;">
        <img src="images/landing/bg-off.png" alt="" class="bg-base" loading="lazy">
        <img src="images/landing/bg-on.png" alt="" class="bg-base bg-hover" loading="lazy">

        <!--Div Border-->
        <div class="border-glow" style="position: absolute; inset: 0; box-sizing: border-box; z-index: 1; margin: 5rem; border-radius: 10px; pointer-events: none;"></div>
        <div class="border-gradient" style="position: absolute; inset: 0; box-sizing: border-box; z-index: 1; margin: 5rem; border-radius: 10px; pointer-events: none;"></div>

        <div class="tagline-wrapper" style="position: absolute; inset: 0; margin: 5rem; display: flex; align-items: center; z-index: 2; pointer-events: none; padding-left: 5rem;">
            <div style="width: 20%; position: relative;">
                <div class="content-default">
                    <div class="tagline">Lighting the Way to Efficiency</div>
                    <div class="subtagline">Choose smarter energy</div>
                    <div class="subtagline">Choose LumineSense</div>
                </div>
                <div class="content-hover" style="position: absolute; inset: 0;">
                    <div class="tagline">Choose Smarter Energy</div>
                    <div class="subtagline">Choose</div>
                    <div class="subtagline">LumineSense</div>
                </div>
            </div>
        </div>

        <div id="landing" class="landing-container zoom-animation ms-auto" style="position: relative; z-index: 4; padding-right: 7rem;">

            <img src="images/logo.png" alt="LumineSense Logo" class="mb-5">

            <div class="text-center text-uppercase bold mb-2" style="color:var(--secondary-color-1); font-size: 16px;">
                Log In As
            </div>

            <div class="d-flex gap-2">
                <button class="medium" onclick="dissolve('pages/faculty-login.php')" style="flex: 1; height: 110px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <i class="bi bi-person-badge" style="font-size: 1.8rem; margin-bottom: 0.3rem;"></i>
                    Faculty
                </button>
                <button class="medium" onclick="dissolve('pages/admin-login.php')" style="flex: 1; height: 110px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <i class="bi bi-person-gear" style="font-size: 1.8rem; margin-bottom: 0.3rem;"></i>
                    Admin
                </button>
            </div>

        </div>
        <img src="images/landing/overlay.svg" alt="" style="position: absolute; bottom: 0; right: 0; width: auto; height: 100%; z-index: 3; pointer-events: none;">
    </div>

    <script src="script/animations.js"></script>
    <script>
        document.querySelector('.parent-container-index').addEventListener('mousemove', function(e) {
            this.querySelector('.bg-hover').classList.toggle('active', e.clientX < window.innerWidth / 2);
        });
        document.querySelector('.parent-container-index').addEventListener('mouseleave', function() {
            this.querySelector('.bg-hover').classList.remove('active');
        });
    </script>
</body>

</html>