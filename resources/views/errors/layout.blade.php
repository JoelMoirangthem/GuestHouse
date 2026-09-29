{{--
  Standalone error layout. Deliberately depends on nothing that can itself be
  broken when an error page is needed: no Vite manifest, no session, no auth,
  no database. Styles are inline for the same reason.

  It never prints an exception message for a 5xx, a stack trace, a file path
  or a query — SECURITY.md section 6.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Guest House</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               background: #f7f6f3; color: #14181f; font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
        .code { font-size: .75rem; letter-spacing: .12em; text-transform: uppercase; color: #6b7484; margin: 0 0 .5rem; }
        h1 { font-size: 1.375rem; margin: 0 0 .75rem; color: #16283e; }
        p { line-height: 1.6; color: #3b4452; margin: 0 0 1.5rem; }
        a { display: inline-block; background: #1d3452; color: #fff; text-decoration: none; padding: .625rem 1.125rem; border-radius: .5rem; font-size: .875rem; }
        a:focus-visible { outline: 3px solid #c9901f; outline-offset: 2px; }
        footer { margin-top: 2rem; font-size: .75rem; color: #9aa1ad; }
    </style>
</head>
<body>
    <main>
        <p class="code">Error @yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="{{ url('/home') }}">Go to your home page</a>
        <footer>{{ config('gh.building') }}, {{ config('gh.institution') }}</footer>
    </main>
</body>
</html>
