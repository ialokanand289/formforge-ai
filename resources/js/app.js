import './bootstrap';

// Alpine is deliberately not imported or started here.
//
// Livewire 3 bundles its own Alpine and starts it as part of @livewireScripts.
// Importing a second copy leaves two instances fighting over the same DOM,
// which silently breaks every wire: directive on the page — buttons stop
// responding with no error the user can see. The layouts load Livewire's
// scripts, so Alpine is available everywhere, exactly once.
