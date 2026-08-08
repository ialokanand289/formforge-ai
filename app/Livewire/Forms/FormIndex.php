<?php

namespace App\Livewire\Forms;

use App\Models\Form;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class FormIndex extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('viewAny', Form::class);
    }

    /**
     * @return Collection<int, Form>
     */
    public function getFormsProperty(): Collection
    {
        return Form::query()
            ->where('user_id', auth()->id())
            ->latest('updated_at')
            ->get();
    }

    #[Layout('layouts.app')]
    #[Title('Forms')]
    public function render()
    {
        return view('livewire.forms.form-index', [
            'forms' => $this->forms,
        ]);
    }
}
