<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class TicketWithdraw  extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'withdraw_method' => 'required',
            'withdraw_account' => 'required',
            'withdraw_amount' => 'required|numeric|min:1',
        ];
    }

    public function messages()
    {
        return [
            'withdraw_method.required' => __('The withdrawal method cannot be empty'),
            'withdraw_account.required' => __('The withdrawal account cannot be empty'),
            'withdraw_amount.required' => __('Withdrawal amount cannot be empty'),
            'withdraw_amount.numeric' => __('Withdrawal amount must be a number'),
            'withdraw_amount.min' => __('Withdrawal amount must be at least 1 cent'),
        ];
    }
}
