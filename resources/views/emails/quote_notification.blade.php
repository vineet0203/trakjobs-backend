{{-- resources/views/emails/quote_notification.blade.php --}}
@extends('emails.layout')

@section('title', $title)
@section('greeting', $greeting)

@section('content')
    <p>Dear {{ $name }},</p>
    
    <p>{{ $body }}</p>
    
    <div class="info-box">
        <strong>Quotation Number:</strong> {{ $quoteNumber }}<br>
        <strong>Quotation Title:</strong> {{ $quoteTitle }}<br>
        <strong>Total Amount:</strong> {{ $totalAmount }}
    </div>
    
    <p>Please log in to your Customer Panel to review, accept, or reject the quotation.</p>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $loginUrl }}" class="button" style="color: white; text-decoration: none;">Go to Customer Panel</a>
    </div>
@endsection
