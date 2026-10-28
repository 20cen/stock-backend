<?php

namespace App\Http\Requests;

/**
 * Fiche client : nom, téléphone, adresse et courriel facultatif (SFD-CLI). Les homonymes sont permis.
 */
class CustomerRequest extends PartnerRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->commonRules(creating: $this->route('customer') === null);
    }
}
