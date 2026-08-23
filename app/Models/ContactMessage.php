<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Message reçu via le formulaire de contact public (remplace t_contact_us). */
class ContactMessage extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'subject', 'message', 'is_read', 'legacy_id'];

    protected $casts = ['is_read' => 'boolean'];
}
