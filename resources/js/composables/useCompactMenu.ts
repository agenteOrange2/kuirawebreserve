import { ref } from 'vue';

const compactMenu = ref(false);

export function useCompactMenu() {
  const setCompactMenu = (value: boolean) => {
    compactMenu.value = value;
  };

  return {
    compactMenu,
    setCompactMenu,
  };
}
