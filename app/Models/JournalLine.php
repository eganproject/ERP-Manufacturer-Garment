<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    /** Pelanggan / supplier / SPK / order maklon yang menjadi rincian pembantu baris ini. */
    public function reference()
    {
        return $this->morphTo();
    }

    public function referenceLabel(): ?string
    {
        $ref = $this->reference;

        return match (true) {
            $ref === null => null,
            isset($ref->number) => $ref->number,
            isset($ref->name) => $ref->name,
            default => null,
        };
    }
}
