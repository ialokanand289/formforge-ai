@props([
    'field',
])

<div>
    <x-dynamic-component :component="'form::fields.'.$field['type']" :field="$field" />
</div>
