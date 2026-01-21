import NetInfo from '@react-native-community/netinfo'

export const isOnline = async () => {
  const state = await NetInfo.fetch()
  return Boolean(state.isConnected && state.isInternetReachable !== false)
}
