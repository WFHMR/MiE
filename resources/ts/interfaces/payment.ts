export class Payment {
    label: string;
    identifier: string;
    selected: boolean;
    readonly: boolean;
    value: number;
    id?: number;

    /**
     * What the cashier typed to identify the transfer on the other side: a PromptPay slip
     * reference, a welfare card approval code, a bank transfer time. Only the channels
     * that settle after the sale need one, so it is optional.
     */
    reference?: string;
}