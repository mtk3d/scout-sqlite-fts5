<?php

declare(strict_types=1);

namespace Wrenchr\Scout\Fts5\Tests;

use PHPUnit\Framework\Attributes\Test;
use Wrenchr\Scout\Fts5\Exceptions\ScoutFts5Exception;
use Wrenchr\Scout\Fts5\Tests\Stubs\Customer;

class FilterTest extends TestCase
{
    #[Test]
    public function it_filters_on_a_column_stored_in_the_index(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 2]);

        $this->assertSame(
            ['Adam Kowalski'],
            Customer::search('kowalski')->where('tenant_id', 2)->get()->pluck('name')->all()
        );
    }

    #[Test]
    public function it_filters_on_a_column_that_only_exists_on_the_model(): void
    {
        // `status` is not part of the index. Since the index lives in the same
        // SQLite database as the models, the filter is answered by joining
        // the model's own table rather than being ignored.
        Customer::create(['name' => 'Jan Kowalski', 'status' => 'active']);
        Customer::create(['name' => 'Adam Kowalski', 'status' => 'archived']);

        $this->assertSame(
            ['Jan Kowalski'],
            Customer::search('kowalski')->where('status', 'active')->get()->pluck('name')->all()
        );
    }

    #[Test]
    public function it_supports_comparison_operators(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 5]);

        $this->assertSame(
            ['Adam Kowalski'],
            Customer::search('kowalski')->where('tenant_id', '>', 3)->get()->pluck('name')->all()
        );
    }

    #[Test]
    public function it_supports_where_in_and_where_not_in(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 2]);
        Customer::create(['name' => 'Ewa Kowalski', 'tenant_id' => 3]);

        $this->assertSame(
            ['Jan Kowalski', 'Adam Kowalski'],
            Customer::search('kowalski')->whereIn('tenant_id', [1, 2])->get()->pluck('name')->all()
        );

        $this->assertSame(
            ['Ewa Kowalski'],
            Customer::search('kowalski')->whereNotIn('tenant_id', [1, 2])->get()->pluck('name')->all()
        );
    }

    #[Test]
    public function it_counts_only_the_documents_that_pass_the_filter(): void
    {
        foreach (range(1, 6) as $number) {
            Customer::create(['name' => "Kowalski {$number}", 'tenant_id' => $number <= 2 ? 1 : 2]);
        }

        $page = Customer::search('kowalski')->where('tenant_id', 1)->paginate(5);

        $this->assertSame(2, $page->total());
    }

    #[Test]
    public function it_applies_filters_to_the_fallback_passes_too(): void
    {
        Customer::create(['name' => 'Jan Kowalski', 'tenant_id' => 1]);
        Customer::create(['name' => 'Adam Kowalski', 'tenant_id' => 2]);

        $result = Customer::search('kowalsky')->where('tenant_id', 2)->raw();

        $this->assertSame('typo', $result->pass());
        $this->assertSame(1, $result->total());
    }

    #[Test]
    public function it_rejects_a_filter_on_an_unknown_field(): void
    {
        Customer::create(['name' => 'Jan Kowalski']);

        $this->expectException(ScoutFts5Exception::class);
        $this->expectExceptionMessageMatches('/not an indexed filter column/');

        Customer::search('kowalski')->where('nie_ma_takiej_kolumny', 1)->get();
    }
}
