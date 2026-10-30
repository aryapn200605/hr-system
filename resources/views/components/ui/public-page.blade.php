<div class="mx-auto flex min-h-screen max-w-5xl flex-col px-4 sm:px-8">
    <header class="border-b border-rule py-3.5">
        <p class="text-sm font-semibold text-ink">{{ config('app.name') }}</p>
    </header>

    <main class="flex-1 py-10 sm:py-16">
        <div class="max-w-sm">
            {{ $slot }}
        </div>
    </main>

    <footer class="border-t border-rule py-3 text-xs text-muted">Internal system. Accounts are created by an HR Admin.</footer>
</div>
