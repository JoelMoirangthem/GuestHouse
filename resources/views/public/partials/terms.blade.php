{{--
  Guest House Terms & Conditions shown before a public requisition is submitted.
  Wording is as issued by the Guest House Administration; do not paraphrase.
--}}
@php
    $terms = [
        'Accommodation shall be provided only to the guest(s) whose name(s) are included in the approved guest list. Only the persons listed in the guest list shall be permitted to stay in the room.',
        'Any outside visitor shall report at the Reception/Security and produce a valid photo ID. Outside visitors shall not be permitted inside guest rooms.',
        'Accommodation for unmarried couples is not permitted. Only married couples may occupy a room together.',
        'Income Tax Department Official ID Card of the applicant/guest is mandatory at the time of check-in and the details shall be entered in the Guest/Accommodation Register.',
        'Check-in and check-out shall be strictly as per the timings prescribed by the Guest House Administration. Extension of stay requires prior permission.',
        'Party, open party, social gathering, loud music, DJ or any activity causing disturbance to other occupants is strictly prohibited.',
        'Unauthorised persons, unauthorised overnight stay, subletting or transfer of allotted accommodation is prohibited.',
        'Smoking and consumption/possession of prohibited substances or any illegal activity within the premises is strictly prohibited.',
        'Guests shall maintain cleanliness, discipline and silence and shall not cause inconvenience to other occupants.',
        'Any damage to Government property, furniture, fixtures, linen or equipment shall be recoverable as per applicable rules.',
        'Fire-safety equipment and emergency exits shall not be obstructed or misused. Cooking/open flame in rooms is not permitted.',
        'CCTV/Security arrangements in common areas may be used for security purposes. Guests/visitors shall comply with security instructions.',
        'The Guest House Administration may take appropriate action, including cancellation of accommodation or restriction of entry, in case of violation of these terms or for security/administrative reasons.',
        'Guest House charges: ₹600 per room for training-related stay and ₹900 per room for other stays.',
        'A separate Non-AC Dormitory facility is available for drivers. Guest House rooms shall not be allotted to drivers.',
        'After submission of the booking requisition, please allow at least 12 hours for processing. Kindly avoid unnecessary calls for status/information. Please report at the address mentioned above; Reception and Security personnel will be available to facilitate you.',
    ];
@endphp

<h2 id="terms-title" class="text-base font-bold uppercase tracking-wide text-[--color-ink]">TERMS &amp; CONDITIONS</h2>

<ol class="mt-3 list-decimal space-y-2 pl-6 text-sm leading-relaxed text-[--color-ink-soft]">
    @foreach ($terms as $term)
        <li>{{ $term }}</li>
    @endforeach
</ol>

<p class="mt-4 text-sm leading-relaxed text-[--color-ink]">
    <span class="font-semibold">Declaration:</span>
    I certify that the information furnished above is correct and undertake to comply with the above Terms &amp; Conditions.
</p>

<p class="mt-3 text-sm text-[--color-ink]">
    <span class="font-semibold">Reception Contact No.:</span>
    <a href="tel:9918143306" class="text-navy-700 underline">9918143306</a>,
    <a href="tel:9415984377" class="text-navy-700 underline">9415984377</a>,
    <a href="tel:8477862418" class="text-navy-700 underline">8477862418</a>
</p>
