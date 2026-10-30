<x-layouts::auth title="Invitation not valid">
    <x-ui.auth-heading title="This invitation is not valid">The link may have expired, been replaced by a newer one, or been used already. Ask your HR Admin to send a new invitation.</x-ui.auth-heading>

    <x-ui.button variant="link" :href="route('login')">Go to login</x-ui.button>
</x-layouts::auth>
