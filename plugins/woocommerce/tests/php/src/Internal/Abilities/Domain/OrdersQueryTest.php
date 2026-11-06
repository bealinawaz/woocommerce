<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Abilities\Domain\OrdersQuery;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Unit_Test_Case;

/**
 * Tests for the orders-query ability.
 */
class OrdersQueryTest extends WC_Unit_Test_Case {

	use HPOSToggleTrait;

	/**
	 * Whether HPOS was authoritative before the test.
	 *
	 * @var bool
	 */
	private $previous_hpos_state;

	/**
	 * Ensure permanent HPOS tables exist before per-test transactions start.
	 */
	public static function wpSetUpBeforeClass(): void {
		self::setup_cot_tables();
	}

	/**
	 * Allow the tests to switch order storage.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->previous_hpos_state = OrderUtil::custom_orders_table_usage_is_enabled();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Restore the order storage of the suite.
	 */
	public function tearDown(): void {
		try {
			OrderHelper::toggle_cot_feature_and_usage( $this->previous_hpos_state );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should return each order exactly once across pages when orders tie on $orderby $order, with HPOS $hpos.
	 * @testWith [true, "date", "desc"]
	 *           [true, "total", "asc"]
	 *           [false, "date", "desc"]
	 *           [false, "total", "asc"]
	 * @param bool   $hpos    Whether HPOS stores the orders.
	 * @param string $orderby Sort field.
	 * @param string $order   Sort direction.
	 */
	public function test_pages_tied_orders_once_each( bool $hpos, string $orderby, string $order ): void {
		OrderHelper::toggle_cot_feature_and_usage( $hpos );
		$ids = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$ids[] = $this->create_order( 10 );
		}

		$paged = array();
		for ( $page = 1; $page <= 3; $page++ ) {
			$result = OrdersQuery::execute(
				array(
					'orderby'  => $orderby,
					'order'    => $order,
					'page'     => $page,
					'per_page' => 3,
				)
			);
			$this->assertIsArray( $result );
			$paged = array_merge( $paged, array_column( $result['orders'], 'id' ) );
		}

		if ( 'desc' === $order ) {
			rsort( $ids );
		}
		$this->assertSame( $ids, $paged, 'Tied orders should be sorted by ID in the requested direction.' );
	}

	/**
	 * @testdox Should sort orders by total, with HPOS $hpos.
	 * @testWith [true]
	 *           [false]
	 * @param bool $hpos Whether HPOS stores the orders.
	 */
	public function test_sorts_by_total( bool $hpos ): void {
		OrderHelper::toggle_cot_feature_and_usage( $hpos );
		$thirty = $this->create_order( 30 );
		$ten    = $this->create_order( 10 );
		$twenty = $this->create_order( 20 );

		$result = OrdersQuery::execute(
			array(
				'orderby' => 'total',
				'order'   => 'asc',
			)
		);

		$this->assertSame( array( $ten, $twenty, $thirty ), array_column( $result['orders'], 'id' ) );
	}

	/**
	 * Create a processing order with a fixed creation date.
	 *
	 * @param float $total Order total.
	 * @return int
	 */
	private function create_order( float $total ): int {
		$order = wc_create_order();
		$order->set_status( OrderStatus::PROCESSING );
		$order->set_date_created( '2026-01-01T10:00:00' );
		$order->set_total( $total );
		$order->save();

		return $order->get_id();
	}
}
