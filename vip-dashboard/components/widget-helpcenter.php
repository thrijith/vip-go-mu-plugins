<?php
function render_vip_dashboard_widget_helpcenter() {
	?>
	<div class="widget">
		<h2 class="widget__title">Search the WPVIP Knowledge Base</h2>

		<div id="vip_dashboard_message_container"></div>

		<form id="vip_dashboard_search_form" class="widget__contact-form">
			<div class="contact-form__row">
				<div class="contact-form__label">
					<input id="vip_kb_form_submit" type="submit" value="Send Request">
				</div>
				<div class="contact-form__input">
					<input type="text" value="<?php echo esc_attr( $current_user->display_name ); ?>"
						id="vip_kb_search_query" placeholder="Search..."/>
				</div>
			</div>

			<div class="contact-form__row submit-button">
				<div class="contact-form__label">
					<label></label>
				</div>
			</div>
		</form>

		<div id="vip_dashboard_kb_search_results" style="clear: both;">
		</div>
	</div>

	<script>
		document.getElementById( 'vip_kb_form_submit' ).addEventListener( 'click', vip_search_kb, false );

		function vip_search_kb( event ) {
			event.preventDefault();

			var search_string = document.getElementById( 'vip_kb_search_query' ).value;
			var url = 'https://olope-testing.go-vip.net/wp-json/zendesk/v1/search?query=' + encodeURIComponent( search_string );
			var kb_request = new XMLHttpRequest();

			kb_request.withCredentials = false;
			kb_request.open( 'GET', url, true );
			kb_request.setRequestHeader( 'Content-Type', 'application/json' );
			kb_request.onload = vip_kb_show_results;
			kb_request.send();
		}

		function vip_kb_show_results() {
			var resultDiv = document.getElementById( 'vip_dashboard_kb_search_results' );
			var data = JSON.parse( this.responseText ).data[0];

			// Clear previous results.
			resultDiv.innerHTML = '';

			if ( data.results.length ) {
				var resultList = document.createElement( 'ul' );
				resultDiv.appendChild( resultList );

				for ( var i = 0; i < data.results.length; ++i ) {
					var article = data.results[ i ];
					var resultItem = document.createElement( 'li' );
					var resultItemLink = document.createElement( 'a' );

					resultItemLink.setAttribute( 'href', encodeURI( article.html_url ) );
					resultItemLink.setAttribute( 'target', '_blank' );
					resultItemLink.innerText( article.title );

					resutItem.appendChild( resultItemLink );
					resultList.appendChild( resultItem );
				}
			} else {
				var result = document.createElement( 'p' );
				result.innerHTML = 'No results';
				resultDiv.appendChild( result );
			}
		}
	</script>
	<?php
}
