@props(['title' => 'Torque'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Torque</title>
    {{-- Paint the stored theme (and sidebar state) before the first frame, so a
         reload never flashes the wrong palette. --}}
    <script{!! \Webpatser\Torque\Torque::cspNonceAttribute() !!}>
        try {
            document.documentElement.setAttribute('data-theme', localStorage.getItem('tq.theme') || 'dark');
            if (localStorage.getItem('tq.nav') === '1') document.documentElement.classList.add('nav-collapsed');
        } catch (e) { document.documentElement.setAttribute('data-theme', 'dark'); }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{ \Webpatser\Torque\Torque::css() }}
    @livewireStyles(['nonce' => \Webpatser\Torque\Torque::cspNonceValue()])
</head>
<body>
    {{-- Dashboard chrome: theme, sidebar collapse, refresh popover, copy button
         and row links.

         Deliberately plain JavaScript, no Alpine. Alpine compiles every
         directive expression (x-data, x-show, @click, :class, $store) with
         `new Function`, which a Content-Security-Policy without 'unsafe-eval'
         blocks: the expressions then fail silently and the chrome is dead
         while Livewire (which needs no eval) keeps the page looking alive.
         Torque advertises nonce support, so the chrome must run under a strict
         CSP: this script carries the nonce and uses no eval.

         All handlers are delegated from `document`, so they survive Livewire
         morphs and wire:navigate. Client-only state (theme, nav) lives on
         <html>, outside the Livewire root, where the morph never touches it. --}}
    <script{!! \Webpatser\Torque\Torque::cspNonceAttribute() !!}>
        (function () {
            var root = document.documentElement;

            function store(key, value) { try { localStorage.setItem(key, value); } catch (e) {} }

            function closePopovers(except) {
                document.querySelectorAll('.popover.open').forEach(function (panel) {
                    if (panel === except) return;
                    panel.classList.remove('open');
                    var anchor = panel.closest('.popover-anchor');
                    var trigger = anchor && anchor.querySelector('[data-torque-action="toggle-popover"]');
                    if (trigger) trigger.setAttribute('aria-expanded', 'false');
                });
            }

            if (window.torqueChrome) return;

            window.torqueChrome = {
                toggleTheme: function () {
                    var theme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                    root.setAttribute('data-theme', theme);
                    store('tq.theme', theme);
                },
                toggleNav: function () {
                    var collapsed = root.classList.toggle('nav-collapsed');
                    store('tq.nav', collapsed ? '1' : '0');
                },
                closePopovers: closePopovers,
            };

            document.addEventListener('click', function (event) {
                var target = event.target instanceof Element ? event.target : event.target.parentElement;
                if (! target) return;

                var hook = target.closest('[data-torque-action]');
                var action = hook ? hook.getAttribute('data-torque-action') : null;

                if (action === 'toggle-theme') return window.torqueChrome.toggleTheme();
                if (action === 'toggle-nav') return window.torqueChrome.toggleNav();

                if (action === 'toggle-popover') {
                    var anchor = hook.closest('.popover-anchor');
                    var panel = anchor && anchor.querySelector('.popover');
                    if (! panel) return;
                    var open = panel.classList.toggle('open');
                    hook.setAttribute('aria-expanded', open ? 'true' : 'false');
                    return closePopovers(panel);
                }

                if (action === 'copy') {
                    var text = hook.getAttribute('data-torque-copy') || '';
                    try { navigator.clipboard && navigator.clipboard.writeText(text); } catch (e) {}
                    var label = hook.querySelector('[data-torque-copy-label]');
                    if (label && ! label.hasAttribute('data-torque-busy')) {
                        var original = label.textContent;
                        label.setAttribute('data-torque-busy', '1');
                        label.textContent = 'Copied';
                        setTimeout(function () {
                            label.textContent = original;
                            label.removeAttribute('data-torque-busy');
                        }, 1500);
                    }
                    return;
                }

                // Refresh interval: highlight the picked option right away (the panel
                // is wire:ignore'd, so the server render never repaints it) and call
                // the component directly. Livewire.find() returns the $wire proxy and
                // .call() is plain JS, so this works without expression evaluation
                // under a CSP without unsafe-eval, where wire:click="method(arg)" may not.
                if (action === 'set-poll') {
                    var owner = hook.closest('.popover');
                    if (owner) {
                        owner.querySelectorAll('.popover-item.active').forEach(function (item) {
                            item.classList.remove('active');
                        });
                        hook.classList.add('active');
                    }
                    closePopovers();

                    var root = hook.closest('[wire\\:id]');
                    var id = root && root.getAttribute('wire:id');
                    var component = id && window.Livewire && window.Livewire.find(id);
                    if (component) {
                        component.call('setPollInterval', Number(hook.getAttribute('data-torque-value')));
                    }
                    return;
                }

                if (action === 'close-popover') return closePopovers();

                // Any click outside an open panel closes it.
                if (target.closest('.popover')) return;
                closePopovers();

                var row = target.closest('[data-torque-href]');
                if (! row || target.closest('a, button, input, select, textarea, label, [wire\\:click]')) return;

                var href = row.getAttribute('data-torque-href');
                if (window.Livewire && typeof window.Livewire.navigate === 'function') {
                    window.Livewire.navigate(href);
                } else {
                    window.location.assign(href);
                }
            });
        })();
    </script>

    {{ $slot }}

    @livewireScripts(['nonce' => \Webpatser\Torque\Torque::cspNonceValue()])
</body>
</html>
