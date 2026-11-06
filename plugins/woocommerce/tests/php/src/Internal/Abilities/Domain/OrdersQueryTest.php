<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

use Automattic\WooCommerce\Internal\Abilities\Domain\OrdersQuery;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Helper_Order;
use WC_Helper_Product;
use WC_Unit_Test_Case;

/**
 * Tests for the orders-query ability.
 */
class OrdersQueryTest extends WC_Unit_Test_Case {

	use HPOSToggleTrait;

	/**
	 * HPOS usage before the test, restored in tearDown.
	 *
	 * @var bool
	 */
	private $original_hpos;

	/**
	 * Create the HPOS tables, keeping the storage the suite runs on.
	 *
	 * @param \WP_UnitTest_Factory $factory WordPress unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		$previous_hpos_state = OrderUtil::custom_orders_table_usage_is_enabled();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		try {
			self::setup_cot_tables();
			if ( OrderUtil::custom_orders_table_usage_is_enabled() !== $previous_hpos_state ) {
				OrderHelper::toggle_cot_feature_and_usage( $previous_hpos_state );
			}
		} finally {
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
	}

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_hpos = OrderUtil::custom_orders_table_usage_is_enabled();
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
	}

	/**
	 * Restore the order storage, which the base class does not reset.
	 */
	public function tearDown(): void {
		try {
			$this->toggle_cot_feature_and_usage( $this->original_hpos );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should find a customer's orders by first name, last name or the start of the billing email, with HPOS and with posts storage.
	 *
	 * @testWith [true, "Jane"]
	 *           [true, "doe"]
	 *           [true, "jane.doe@"]
	 *           [false, "Jane"]
	 *           [false, "doe"]
	 *           [false, "jane.doe@"]
	 *
	 * @param bool   $hpos   Whether HPOS is on.
	 * @param string $search Search text.
	 */
	public function test_search_matches_customer( bool $hpos, string $search ): void {
		$this->toggle_cot_feature_and_usage( $hpos );
		$jane = $this->create_order( 'Jane', 'Doe', 'jane.doe@example.com' );
		$this->create_order( 'John', 'Smith', 'john@example.com' );

		$result = OrdersQuery::execute( array( 'search' => $search ) );

		$this->assertSame( array( $jane ), array_column( $result['orders'], 'id' ) );
	}

	/**
	 * @testdox Should find orders by the name of an ordered product, with HPOS and with posts storage.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $hpos Whether HPOS is on.
	 */
	public function test_search_matches_product_name( bool $hpos ): void {
		$this->toggle_cot_feature_and_usage( $hpos );
		$this->create_order( 'Jane', 'Doe', 'jane.doe@example.com' );
		$mug = $this->create_order( 'John', 'Smith', 'john@example.com', 'Enamel mug' );

		$result = OrdersQuery::execute( array( 'search' => 'Enamel' ) );

		$this->assertSame( array( $mug ), array_column( $result['orders'], 'id' ) );
	}

	/**
	 * @testdox Should return no orders when nothing matches the search, with HPOS and with posts storage.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $hpos Whether HPOS is on.
	 */
	public function test_search_without_matches_returns_nothing( bool $hpos ): void {
		$this->toggle_cot_feature_and_usage( $hpos );
		$this->create_order( 'Jane', 'Doe', 'jane.doe@example.com' );

		$result = OrdersQuery::execute( array( 'search' => 'Nobody' ) );

		$this->assertSame( array(), $result['orders'] );
		$this->assertSame( 0, $result['total_pages'] );
	}

	/**
	 * @testdox Should apply customer_id and exclude to the orders a search matches, with HPOS and with posts storage.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $hpos Whether HPOS is on.
	 */
	public function test_search_combines_with_other_filters( bool $hpos ): void {
		$this->toggle_cot_feature_and_usage( $hpos );
		$customer_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		$guest       = $this->create_order( 'Jane', 'Doe', 'jane.doe@example.com' );
		$first       = $this->create_order( 'Jane', 'Doe', 'jane.doe@example.com', null, $customer_id );
		$second      = $this->create_order( 'Jane', 'Doe', 'jane.doe@example.com', null, $customer_id );

		$by_customer = OrdersQuery::execute(
			array(
				'search'      => 'Jane',
				'customer_id' => $customer_id,
			)
		);
		$excluded    = OrdersQuery::execute(
			array(
				'search'  => 'Jane',
				'exclude' => array( $first ),
			)
		);

		$this->assertEqualsCanonicalizing( array( $first, $second ), array_column( $by_customer['orders'], 'id' ) );
		$this->assertEqualsCanonicalizing( array( $guest, $second ), array_column( $excluded['orders'], 'id' ) );
	}

	/**
	 * @testdox Should refuse a search that is empty or only whitespace.
	 *
	 * @testWith [""]
	 *           ["  "]
	 *
	 * @param string $search Search text.
	 */
	public function test_rejects_blank_search( string $search ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$result = wp_get_ability( OrdersQuery::get_name() )->execute( array( 'search' => $search ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Create an order with one product and the given billing and shipping names.
	 *
	 * @param string      $first_name   Billing first name.
	 * @param string      $last_name    Billing last name.
	 * @param string      $email        Billing email.
	 * @param string|null $product_name Name of the ordered product, or null for the helper's default.
	 * @param int         $customer_id  Customer ID, 0 for a guest.
	 * @return int Order ID.
	 */
	private function create_order( string $first_name, string $last_name, string $email, ?string $product_name = null, int $customer_id = 0 ): int {
		$product = WC_Helper_Product::create_simple_product();
		if ( null !== $product_name ) {
			$product->set_name( $product_name );
			$product->save();
		}

		$order = WC_Helper_Order::create_order( $customer_id, $product );
		$order->set_billing_first_name( $first_name );
		$order->set_billing_last_name( $last_name );
		$order->set_billing_email( $email );
		$order->set_shipping_first_name( $first_name );
		$order->set_shipping_last_name( $last_name );
		$order->save();

		return $order->get_id();
	}
}
