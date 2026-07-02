@extends('emails.layout')

@section('title', 'Verify Your TrakJobs Account')

@section('greeting', 'Account Verification')

@section('content')
    <p>Hello {{ $name }},</p>
    <p>To complete your TrakJobs account verification, please enter the following 6-digit One-Time Passcode (OTP):</p>
    
    <div style="text-align: center; margin: 30px 0;">
        <span style="font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #0F2744; background: #f1f5f9; padding: 15px 30px; border-radius: 8px; border: 1px solid #cbd5e1; display: inline-block;">
            {{ $code }}
        </span>
    </div>
    
    <p>This passcode is valid for the next 10 minutes. If you did not request this code, please ignore this email or contact support.</p>
    
    <p>Best regards,<br>The TrakJobs Team</p>
@endsection
