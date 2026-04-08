<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Page introuvable</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #0f0e17;
            color: #fffffe;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .container {
            text-align: center;
            max-width: 480px;
        }

        .code {
            font-size: clamp(6rem, 20vw, 10rem);
            font-weight: 800;
            line-height: 1;
            background: linear-gradient(135deg, #7c3aed, #a855f7, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-top: 1rem;
            color: #e2e8f0;
        }

        p {
            margin-top: 0.75rem;
            color: #94a3b8;
            line-height: 1.6;
        }

        a {
            display: inline-block;
            margin-top: 2rem;
            padding: 0.75rem 1.75rem;
            background: linear-gradient(135deg, #7c3aed, #a855f7);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: opacity .2s;
        }

        a:hover { opacity: .85; }
    </style>
</head>
<body>
    <div class="container">
        <div class="code">404</div>
        <h1>Page introuvable</h1>
        <p>La page que vous cherchez n'existe pas ou a été déplacée.</p>
        <a href="/">Retour à l'accueil</a>
    </div>
</body>
</html>
