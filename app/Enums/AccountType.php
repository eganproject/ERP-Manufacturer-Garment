<?php

namespace App\Enums;

/**
 * Golongan akun pada bagan akun (COA). Menentukan saldo normal dan posisi di laporan keuangan.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case CostOfSales = 'cost_of_sales';
    case Expense = 'expense';
    case OtherIncome = 'other_income';
    case OtherExpense = 'other_expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Aset',
            self::Liability => 'Kewajiban',
            self::Equity => 'Ekuitas',
            self::Revenue => 'Pendapatan',
            self::CostOfSales => 'Harga Pokok Penjualan',
            self::Expense => 'Beban Usaha',
            self::OtherIncome => 'Pendapatan Lain-lain',
            self::OtherExpense => 'Beban Lain-lain',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Asset => 'blue',
            self::Liability => 'amber',
            self::Equity => 'indigo',
            self::Revenue, self::OtherIncome => 'green',
            self::CostOfSales, self::Expense, self::OtherExpense => 'red',
        };
    }

    /** Saldo normal: debit untuk aset & beban, kredit untuk kewajiban, ekuitas & pendapatan. */
    public function normalBalance(): string
    {
        return in_array($this, [self::Asset, self::CostOfSales, self::Expense, self::OtherExpense], true) ? 'debit' : 'credit';
    }

    /** Akun neraca (saldo dibawa ke periode berikutnya) vs akun laba rugi (ditutup tiap tahun buku). */
    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], true);
    }

    /**
     * @return list<self>
     */
    public static function profitLoss(): array
    {
        return [self::Revenue, self::CostOfSales, self::Expense, self::OtherIncome, self::OtherExpense];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}
