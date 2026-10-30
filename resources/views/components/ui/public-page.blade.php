{{-- A narrow block in the middle of the screen. Text inside it stays left aligned. --}}
<div class="flex min-h-screen flex-col px-4">
    <main class="flex flex-1 flex-col justify-center py-8 sm:py-12">
        <div class="mx-auto w-full max-w-md">
            <p class="mb-4 text-sm font-semibold text-ink">{{ config('app.name') }}</p>

            <div class="rounded-sm border border-rule bg-surface p-5 sm:p-6">
                {{ $slot }}
            </div>
        </div>
    </main>

    <footer class="mx-auto w-full max-w-md pb-6 text-xs text-muted">Internal system. Accounts are created by an HR Admin.</footer>
</div>
