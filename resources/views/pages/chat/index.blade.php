@extends('layouts.app')
@section('title', 'Chat')
@section('body-class', 'chat-page')

@section('content')
    <livewire:chat.conversation :room="$room ?? null"/>
@endsection
