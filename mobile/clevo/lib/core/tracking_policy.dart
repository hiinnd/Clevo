/// Mirrors transport/privacy limits only; the PHP state machine authorizes every sample.
const sharingStages = {
  'heading_pickup',
  'arrived_pickup',
  'vehicle_collected',
  'returning_vehicle',
};
bool canShareLocation(
  Map<String, dynamic> tracking, {
  required bool foreground,
}) =>
    foreground &&
    tracking['sharing_allowed'] == true &&
    sharingStages.contains(tracking['delivery_stage']);
bool reusableSample(Map<String, dynamic> sample, DateTime now) {
  final captured = DateTime.tryParse('${sample['captured_at']}');
  return captured != null &&
      now.toUtc().difference(captured.toUtc()).inSeconds <= 110 &&
      !captured.isAfter(now.toUtc().add(const Duration(seconds: 10)));
}
