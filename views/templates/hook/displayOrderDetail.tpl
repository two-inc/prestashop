{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *}

<div id="two-payment-info" class="box">
    <h4>{l s='Two Payment Info' mod='twopayment'}</h4>
    <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <tbody>
                {if $twopaymentdata.two_order_id}
                    <tr><td><strong>{l s='Two Order ID' mod='twopayment'}</strong></td> <td>{$twopaymentdata.two_order_id}</td></tr>
                {/if}
                {if $twopaymentdata.two_order_reference}
                    <tr><td><strong>{l s='Two Order Reference' mod='twopayment'}</strong></td> <td>{$twopaymentdata.two_order_reference}</td></tr>
                {/if}
                {if $twopaymentdata.two_day_on_invoice}
                    <tr><td><strong>{l s='Two Day On Invoice' mod='twopayment'}</strong></td> <td>{$twopaymentdata.two_day_on_invoice}</td></tr>
                {/if}
                {if $twopaymentdata.two_invoice_url}
                    <tr><td><strong>{l s='Two Invoice Url' mod='twopayment'}</strong></td> <td><a href="{$twopaymentdata.two_invoice_url}" target="_blank">{$twopaymentdata.two_invoice_url}</a></td></tr>
                {/if}
            </tbody>
        </table>
    </div>
</div>