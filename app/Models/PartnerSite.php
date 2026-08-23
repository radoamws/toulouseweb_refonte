<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Site partenaire affiché en page contact (remplace t_contact_sites). */
class PartnerSite extends Model
{
    protected $fillable = ['name', 'url', 'logo', 'category', 'order'];
}
