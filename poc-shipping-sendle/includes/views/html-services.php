<tr valign="top" id="service_options">
	<th scope="row" class="titledesc"><?php _e( 'Services', 'poc-shipping-sendle' ); ?></th>
	<td class="forminp">
		<table class="sendleservices widefat">
			<thead>
			<th class="sort">&nbsp;</th>
			<th style="text-align:center; padding: 10px;"><?php _e( 'Service', 'poc-shipping-sendle' ); ?></th>
			<th style="text-align:center; padding: 10px;"><?php _e( 'Enable', 'poc-shipping-sendle' ); ?></th>
			<th><?php _e( 'Name', 'poc-shipping-sendle' ); ?></th>
			<th style="text-align:center; padding: 10px;"><?php _e( 'Extra Cover', 'poc-shipping-sendle' ); ?></th>
			<th style="text-align:center; padding: 10px;"><?php _e( 'Signature / Registered', 'poc-shipping-sendle' ); ?></th>
			<th><?php echo sprintf( __( 'Adjustment (%s)', 'poc-shipping-sendle' ), get_woocommerce_currency_symbol() ); ?></th>
			<th><?php _e( 'Adjustment (%)', 'poc-shipping-sendle' ); ?></th>
			</thead>
			<tbody>
			<?php
			$sort                   = 0;
			$this->ordered_services = array();

			foreach ( $this->services as $code => $values ) {

				if ( is_array( $values ) ) {
					$name = $values['name'];
				} else {
					$name = $values;
				}

				if ( isset( $this->custom_services[ $code ] ) && isset( $this->custom_services[ $code ]['order'] ) ) {
					$sort = $this->custom_services[ $code ]['order'];
				}

				while ( isset( $this->ordered_services[ $sort ] ) ) {
					$sort++;
				}

				$other_service_codes = isset( $values['alternate_services'] ) ? $values['alternate_services'] : '';

				$this->ordered_services[ $sort ] = array( $code, $name, $other_service_codes );

				$sort++;
			}

			ksort( $this->ordered_services );

			foreach ( $this->ordered_services as $value ) {
				$code                = $value[0];
				$name                = $value[1];
				$other_service_codes = array_filter( (array) $value[2] );

				if ( ! isset( $this->custom_services[ $code ] ) ) {
					$this->custom_services[ $code ] = array();
				}

				foreach ( $other_service_codes as $key => $value ) {
					$other_service_codes[ $key ] = str_replace( $code . '_', '', $value );
				}
				?>
				<tr>
					<td class="sort"><input type="hidden" class="order"
					                        name="sendleservice[<?php echo $code; ?>][order]"
					                        value="<?php echo isset( $this->custom_services[ $code ]['order'] ) ? $this->custom_services[ $code ]['order'] : ''; ?>"/>
					</td>
					<td style="text-align:center"><?php
						echo '<strong data-tip="';

						echo $code;

						if ( $other_service_codes ) {
							echo ', ' . implode( ', ', $other_service_codes );
						}

						echo '" class="tips">';

						if ( ! empty( $this->services[ $code ]['image'] ) ) {
							echo '<img src="' . WC_SHIPPING_AUSTRALIA_POST_PLUGIN_URL . '/' . $this->services[ $code ]['image'] . '" alt="' . $this->services[ $code ]['name'] . '" />';
						} else {
							echo $this->services[ $code ]['name'];
						}

						echo '</strong>';
						?></td>
					<td style="text-align:center"><input type="checkbox"
					                                     name="sendleservice[<?php echo $code; ?>][enabled]" <?php checked( ( ! isset( $this->custom_services[ $code ]['enabled'] ) || ! empty( $this->custom_services[ $code ]['enabled'] ) ), true ); ?> />
					</td>
					<td><input type="text" name="sendleservice[<?php echo $code; ?>][name]"
					           placeholder="<?php echo $name; ?> (<?php echo $this->title; ?>)"
					           value="<?php echo isset( $this->custom_services[ $code ]['name'] ) ? $this->custom_services[ $code ]['name'] : ''; ?>"
					           size="50"/></td>
					<td style="text-align:center">
						<?php if ( in_array( $code, array_keys( $this->extra_cover ) ) ) : ?>
							<input type="checkbox"
							       name="sendleservice[<?php echo $code; ?>][extra_cover]" <?php checked( ( ! isset( $this->custom_services[ $code ]['extra_cover'] ) || ! empty( $this->custom_services[ $code ]['extra_cover'] ) ), true ); ?> />
						<?php endif; ?>
					</td>
					<td style="text-align:center">
						<?php if ( in_array( $code, $this->delivery_confirmation ) ) : ?>
							<input type="checkbox"
							       name="sendleservice[<?php echo $code; ?>][delivery_confirmation]" <?php checked( ( ! isset( $this->custom_services[ $code ]['delivery_confirmation'] ) || ! empty( $this->custom_services[ $code ]['delivery_confirmation'] ) ), true ); ?> />
						<?php endif; ?>
					</td>
					<td><input type="text" name="sendleservice[<?php echo $code; ?>][adjustment]"
					           placeholder="N/A"
					           value="<?php echo isset( $this->custom_services[ $code ]['adjustment'] ) ? $this->custom_services[ $code ]['adjustment'] : ''; ?>"
					           size="4"/></td>
					<td><input type="text"
					           name="sendleservice[<?php echo $code; ?>][adjustment_percent]"
					           placeholder="N/A"
					           value="<?php echo isset( $this->custom_services[ $code ]['adjustment_percent'] ) ? $this->custom_services[ $code ]['adjustment_percent'] : ''; ?>"
					           size="4"/></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
	</td>
</tr>
