@extends('public-layout')
@section('title', 'Contact')
@section('content')
    <h1>Contacter {{ config('app.company.name') }}</h1>
    @if (session('contact_received'))
        <p role="status">Votre demande a bien été reçue.</p>
    @endif
    @if ($errors->any())
        <p role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="post" action="{{ route('public.contact.store') }}">
        @csrf
        <label for="contact-name">Nom</label>
        <input id="contact-name" name="name" autocomplete="name" value="{{ old('name') }}" maxlength="120" required @error('name') aria-invalid="true" aria-describedby="contact-name-error" @enderror>
        @error('name') <p id="contact-name-error" class="error">{{ $message }}</p> @enderror
        <label for="contact-email">Courriel</label>
        <input id="contact-email" type="email" name="email" autocomplete="email" value="{{ old('email') }}" maxlength="254" required @error('email') aria-invalid="true" aria-describedby="contact-email-error" @enderror>
        @error('email') <p id="contact-email-error" class="error">{{ $message }}</p> @enderror
        <label for="contact-message">Message</label>
        <textarea id="contact-message" name="message" maxlength="5000" rows="6" required @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>
        @error('message') <p id="contact-message-error" class="error">{{ $message }}</p> @enderror
        <button>Envoyer la demande</button>
    </form>
@endsection
