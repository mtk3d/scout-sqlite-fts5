<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Tests;

use PHPUnit\Framework\Attributes\Test;
use Wrenchr\Scout\Fts5\Tests\Stubs\Customer;

class PaginationTest extends TestCase
{
    #[Test]
    public function it_reports_the_total_beyond_the_current_page(): void
    {
        $this->createCustomers(5);

        $page = Customer::search('kowalski')->paginate(2);

        $this->assertCount(2, $page->items());
        $this->assertSame(5, $page->total());
        $this->assertSame(3, $page->lastPage());
    }

    #[Test]
    public function its_pages_neither_repeat_nor_drop_results(): void
    {
        $this->createCustomers(5);

        $seen = [];

        foreach ([1, 2, 3] as $number) {
            $page = Customer::search('kowalski')->paginate(2, 'page', $number);

            $seen = array_merge($seen, $page->pluck('id')->all());
        }

        $this->assertCount(5, $seen);
        $this->assertSame($seen, array_unique($seen));
    }

    #[Test]
    public function it_orders_by_an_explicit_column_before_slicing_pages(): void
    {
        // The ordering has to reach the index query. Sorting only the models
        // of the current page would sort an arbitrary slice, and every page
        // would hold a different arbitrary slice.
        foreach (range(1, 5) as $number) {
            Customer::create([
                'name' => "Kowalski {$number}",
                'created_at' => now()->addDays($number),
                'updated_at' => now()->addDays($number),
            ]);
        }

        $newest = Customer::search('kowalski')->latest()->paginate(2, 'page', 1);
        $oldest = Customer::search('kowalski')->latest()->paginate(2, 'page', 3);

        $this->assertSame(['Kowalski 5', 'Kowalski 4'], $newest->pluck('name')->all());
        $this->assertSame(['Kowalski 1'], $oldest->pluck('name')->all());
    }

    #[Test]
    public function it_keeps_relevance_order_when_hydrating_models(): void
    {
        Customer::create(['name' => 'Zenon Kowalski']);
        Customer::create(['name' => 'Adam Kowalski', 'notes' => str_repeat('lorem ipsum ', 40)]);

        // Sorted by id the short document would come second; sorted by BM25 it
        // comes first, and that is the order the models must arrive in.
        $this->assertSame(
            ['Zenon Kowalski', 'Adam Kowalski'],
            Customer::search('kowalski')->get()->pluck('name')->all()
        );
    }

    #[Test]
    public function it_pages_through_results_lazily(): void
    {
        $this->createCustomers(3);

        $this->assertCount(3, Customer::search('kowalski')->cursor());
    }

    private function createCustomers(int $count): void
    {
        foreach (range(1, $count) as $number) {
            Customer::create(['name' => "Kowalski {$number}"]);
        }
    }
}
