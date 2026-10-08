/** Text of the input / textarea / select that fired a DOM event (`(input)="x.set(val($event))"`). */
export function eventValue(event: Event): string {
  return (event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement).value;
}
