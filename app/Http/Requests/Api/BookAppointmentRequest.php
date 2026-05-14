<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doctor_id' => 'required|integer|exists:doctors,id',
            'appointment_date' => 'required|date|after_or_equal:today',
            'appointment_time' => 'required|date_format:H:i',
            'type' => 'required|in:in-person,telehealth',
            'reason' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'doctor_id.required' => 'Please select a doctor',
            'doctor_id.exists' => 'Doctor not found',
            'appointment_date.required' => 'Please select a date',
            'appointment_date.after_or_equal' => 'Appointment date must be today or later',
            'appointment_time.required' => 'Please select a time slot',
            'appointment_time.date_format' => 'Invalid time format',
            'type.required' => 'Please select appointment type',
            'type.in' => 'Invalid appointment type',
            'reason.max' => 'Reason cannot exceed 1000 characters',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}