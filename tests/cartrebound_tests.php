<?php
declare( strict_types=1 );

if ( class_exists( "MyTestCase" ) ) {

	final class CartReboundUnitTests extends MyTestCase {


		public function testContentsFromOrder(){
			$order = new WC_Order(110);

			$plugin = new WC_Abandon();
			$contents = $plugin->contentsFromOrder($order);

			$this->assertCount(6, $contents);

			$this->assertNotNull($contents);
			$this->assertEquals($contents[0]['product_title'], "Happy Ninja");
			$this->assertEquals( $contents[0]['quantity'], 19 );
			$this->assertEquals( $contents[0]['variation_id'], 0 );
			$this->assertEquals( $contents[0]['product_price'], 18 );
			$this->assertEquals( $contents[0]['line_total'], $contents[0]['quantity'] * $contents[0]['product_price'] );
		}

		public function testOneIsOne() {
			$this->assertEquals(1,1);

		}
	}

}